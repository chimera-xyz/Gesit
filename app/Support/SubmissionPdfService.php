<?php

namespace App\Support;

use App\Models\ApprovalStep;
use App\Models\FormSubmission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDF;
use setasign\Fpdi\Fpdi;

class SubmissionPdfService
{
    public function __construct(
        private readonly SignatureImageProcessor $signatureImageProcessor,
    ) {
    }

    public function generate(FormSubmission $submission): array
    {
        $submission->loadMissing([
            'form.workflow',
            'user.roles',
            'approvalSteps.approver.roles',
            'approvalSteps.signature.user',
        ]);

        $filename = "GESIT_{$submission->id}.pdf";
        $relativePath = "pdfs/{$filename}";
        $absolutePath = storage_path("app/private/{$relativePath}");
        $directory = dirname($absolutePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            $this->generateWithTemplate($submission, $absolutePath);
        } catch (\Throwable $exception) {
            Log::warning('FPDI PDF generation failed, falling back to DOMPDF: ' . $exception->getMessage());
            $this->generateWithDompdf($submission, $absolutePath);
        }

        $submission->forceFill([
            'pdf_path' => $relativePath,
        ])->save();

        return [
            'filename' => $filename,
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'preview_url' => url("/api/pdf/stream/{$submission->id}"),
            'download_url' => url("/api/pdf/download/{$submission->id}"),
        ];
    }

    public function previewUrl(FormSubmission $submission): string
    {
        return url("/api/pdf/stream/{$submission->id}");
    }

    public function downloadUrl(FormSubmission $submission): string
    {
        return url("/api/pdf/download/{$submission->id}");
    }

    public function absolutePath(FormSubmission $submission): ?string
    {
        if (!$submission->pdf_path) {
            return null;
        }

        return storage_path("app/private/{$submission->pdf_path}");
    }

    public function exists(FormSubmission $submission): bool
    {
        $path = $this->absolutePath($submission);

        return $path !== null && file_exists($path);
    }

    private function generateWithTemplate(FormSubmission $submission, string $absolutePath): void
    {
        $templatePath = $this->templatePdfPath();

        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Template PDF not found: ' . $templatePath);
        }

        $data = $this->buildLayoutData($submission);
        $layout = $this->pdfLayout();
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->setSourceFile($templatePath);
        $templateId = $pdf->importPage(1);
        $templateSize = $pdf->getTemplateSize($templateId);

        $pdf->AddPage($templateSize['orientation'], [$templateSize['width'], $templateSize['height']]);
        $pdf->useTemplate($templateId);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);

        foreach ($this->wipeBoxes($data, $layout) as [$x, $y, $width, $height]) {
            $pdf->Rect($x, $y, $width, $height, 'F');
        }

        $this->drawText($pdf, 396.8, 110.6, $data['request_date'], '', 7.4, 94);
        $this->drawText($pdf, 310.1, 157.5, $data['requester_header'], '', 8.7, 118);
        $this->drawText($pdf, 310.1, 168.6, $data['required_date'], '', 8.5, 118);

        if (strtolower(trim($data['payment_terms'])) !== 'transfer') {
            $this->drawText($pdf, 310.1, 179.7, $data['payment_terms'], '', 8.7, 74);
        }

        $this->drawText($pdf, 56.98, 218.18, $data['item_name'], 'B', 9.2, 210);
        $this->drawText($pdf, 318.94, 218.18, $data['quantity'], '', 8.7, 8, 'C');
        $this->drawText($pdf, 361.86, 218.18, $data['price'], '', 8.7, 54);
        $this->drawText($pdf, 486.18, 218.18, $data['amount'], '', 8.7, 52);
        $this->drawText(
            $pdf,
            $layout['total']['x'],
            $layout['total']['y'],
            $data['total'],
            'B',
            $layout['total']['size'],
            $layout['total']['width'],
            'R'
        );
        $this->drawMultiLineText($pdf, 51.8, 248.52, 244, 10.36, $data['specifications'], '', 7.7);

        $this->drawSignature($pdf, $data['prepared_signature'], ...$layout['signatures']['prepared']);
        $this->drawSignature($pdf, $data['checked_signature'], ...$layout['signatures']['checked']);
        $this->drawSignature($pdf, $data['approved_signature'], ...$layout['signatures']['approved']);

        $this->drawFittedText($pdf, $layout['names']['prepared'], $data['prepared_name']);
        $this->drawFittedText($pdf, $layout['names']['checked'], $data['checked_name']);
        $this->drawFittedText($pdf, $layout['names']['approved'], $data['approved_name']);

        $pdf->Output('F', $absolutePath);
    }

    private function generateWithDompdf(FormSubmission $submission, string $absolutePath): void
    {
        $cleanTemplatePath = public_path('images/requisition-template-clean.png');
        $templateImagePath = file_exists($cleanTemplatePath)
            ? $cleanTemplatePath
            : public_path('images/requisition-template.png');

        $pdf = PDF::loadView('pdf.template', [
            'submission' => $submission,
            'templateImagePath' => $templateImagePath,
            'layout' => $this->pdfLayout(),
        ])->setPaper('a4', 'portrait');

        $pdf->save($absolutePath);
    }

    private function buildLayoutData(FormSubmission $submission): array
    {
        $formData = (array) ($submission->form_data ?? []);
        $formFields = $submission->resolvedFormConfig()['fields'] ?? [];
        $approvalSteps = $submission->approvalSteps->sortBy('step_number')->values();

        $itStep = $approvalSteps->firstWhere('approver_role', 'IT Staff');
        $directorStep = $approvalSteps->firstWhere('approver_role', 'Operational Director');
        $accountingStep = $approvalSteps
            ->where('approver_role', 'Accounting')
            ->sortByDesc('step_number')
            ->first(fn (ApprovalStep $step) => $step->signature_id !== null)
            ?? $approvalSteps->where('approver_role', 'Accounting')->sortByDesc('step_number')->first();
        $approvedStep = $approvalSteps
            ->filter(fn (ApprovalStep $step) => in_array($step->approver_role, ['Operational Director', 'Accounting'], true))
            ->sortByDesc('step_number')
            ->first(fn (ApprovalStep $step) => $step->signature_id !== null || filled(optional($step->approver)->name))
            ?? $directorStep
            ?? $accountingStep;

        $requestDate = $this->resolveFormValue(
            $formData,
            $formFields,
            ['request_date'],
            ['tanggal pengajuan', 'tanggal request'],
            optional($submission->created_at)->toDateString()
        );

        $requiredDate = $this->resolveFormValue(
            $formData,
            $formFields,
            ['needed_by_date'],
            ['required date', 'dibutuhkan sebelum'],
            $requestDate
        );

        $paymentTerms = $this->resolveFormValue(
            $formData,
            $formFields,
            ['payment_terms'],
            ['payment terms', 'metode pembayaran'],
            'Transfer'
        );

        $quantity = (float) $this->resolveFormValue(
            $formData,
            $formFields,
            ['quantity'],
            ['jumlah', 'qty'],
            0
        );

        $amount = (float) $this->resolveFormValue(
            $formData,
            $formFields,
            ['estimated_cost'],
            ['estimasi biaya', 'harga', 'biaya'],
            0
        );

        $pricePerUnit = $quantity > 0 ? $amount / $quantity : $amount;
        $requesterName = $this->resolveFormValue(
            $formData,
            $formFields,
            ['employee_name'],
            ['nama pemohon', 'nama requester'],
            $submission->user?->name ?? '-'
        );

        $requesterSection = $this->resolveFormValue(
            $formData,
            $formFields,
            ['department'],
            ['departemen', 'section'],
            $submission->user?->department ?? '-'
        );

        $requestedPosition = $this->resolveFormValue(
            $formData,
            $formFields,
            ['position'],
            ['jabatan', 'position'],
            null
        );

        $requesterTitle = $submission->user?->roles
            ?->pluck('name')
            ?->reject(fn (string $role) => $role === 'Admin')
            ?->implode(', ') ?: 'Employee';

        $headerRequester = $requestedPosition ?: ($requesterSection ?: $requesterTitle ?: $requesterName);
        $itemName = (string) $this->resolveFormValue(
            $formData,
            $formFields,
            ['item_name'],
            ['nama barang', 'barang apa'],
            $this->resolveFormValue($formData, $formFields, ['item_type'], ['tipe barang', 'jenis barang'], '')
        );

        $specificationLines = array_filter([
            $this->resolveFormValue($formData, $formFields, ['specifications'], ['spesifikasi'], null),
            $this->prefixValue($this->resolveFormValue($formData, $formFields, ['reason'], ['alasan'], null), 'Alasan: '),
            $this->prefixValue($this->resolveFormValue($formData, $formFields, ['urgency'], ['urgensi', 'priority'], null), 'Urgensi: '),
            $this->prefixValue($this->resolveFormValue($formData, $formFields, ['vendor_preference'], ['vendor', 'referensi'], null), 'Referensi: '),
        ]);

        return [
            'request_date' => $this->formatIndonesianDate($requestDate, $submission->created_at),
            'required_date' => $this->formatIndonesianDate($requiredDate, $submission->created_at),
            'payment_terms' => (string) $paymentTerms,
            'requester_header' => (string) $headerRequester,
            'item_name' => trim((string) $itemName),
            'quantity' => $quantity > 0 ? number_format($quantity, 0, ',', '.') : '',
            'price' => $amount > 0 ? $this->formatCurrency($pricePerUnit) : '',
            'amount' => $amount > 0 ? $this->formatCurrency($amount) : '',
            'total' => $amount > 0 ? $this->formatCurrency($amount) : '',
            'specifications' => implode("\n", $specificationLines),
            'prepared_name' => trim((string) $requesterName),
            'checked_name' => optional(optional($itStep)->approver)->name ?? '',
            'approved_name' => optional(optional($approvedStep)->approver)->name ?? '',
            'prepared_signature' => null,
            'checked_signature' => $this->signaturePath($itStep),
            'approved_signature' => $this->signaturePath($approvedStep),
        ];
    }

    private function wipeBoxes(array $data, array $layout): array
    {
        $boxes = [
            [396, 109, 96, 12],
            [309, 156, 120, 12],
            [304, 167, 126, 12],
            [55, 218, 168, 11],
            [308, 218, 28, 11],
            [360, 218, 54, 11],
            [485, 218, 54, 11],
            [51, 248, 126, 11],
            [463, 433, 74, 11],
            [289, 502, 158, 11],
            [302, 556, 130, 11],
            [458, 513, 58, 12],
            [422, 555, 128, 12],
        ];

        if (strtolower(trim($data['payment_terms'])) !== 'transfer') {
            $boxes[] = [309, 179, 76, 11];
        }

        return $boxes;
    }

    private function drawText(
        Fpdi $pdf,
        float $x,
        float $y,
        string $text,
        string $style,
        float $size,
        float $width,
        string $align = 'L',
        string $font = 'Courier'
    ): void {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $pdf->SetFont($font, $style, $size);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $size + 1.4, $this->encodeText($text), 0, 0, $align);
    }

    private function drawMultiLineText(
        Fpdi $pdf,
        float $x,
        float $y,
        float $width,
        float $lineHeight,
        string $text,
        string $style,
        float $size,
        string $font = 'Courier'
    ): void {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $pdf->SetFont($font, $style, $size);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($width, $lineHeight, $this->encodeText($text), 0, 'L');
    }

    private function drawFittedText(Fpdi $pdf, array $layout, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $encoded = $this->encodeText($text);
        $font = $layout['font'] ?? 'Courier';
        $style = $layout['style'] ?? 'B';
        $maxSize = (float) ($layout['size'] ?? 7.0);
        $minSize = (float) ($layout['min_size'] ?? 5.8);
        $width = (float) $layout['width'];
        $size = $maxSize;

        $pdf->SetFont($font, $style, $size);

        while ($size > $minSize && $pdf->GetStringWidth($encoded) > $width) {
            $size = max($minSize, round($size - 0.2, 1));
            $pdf->SetFont($font, $style, $size);
        }

        $pdf->SetXY((float) $layout['x'], (float) $layout['y']);
        $pdf->Cell($width, $size + 1.4, $encoded, 0, 0, $layout['align'] ?? 'C');
    }

    private function drawSignature(Fpdi $pdf, ?string $path, float $x, float $y, float $width, float $height): void
    {
        if (!$path || !file_exists($path)) {
            return;
        }

        $imageSize = @getimagesize($path);

        if (!$imageSize || empty($imageSize[0]) || empty($imageSize[1])) {
            $pdf->Image($path, $x, $y, $width, $height);

            return;
        }

        $sourceWidth = (float) $imageSize[0];
        $sourceHeight = (float) $imageSize[1];
        $scale = min($width / $sourceWidth, $height / $sourceHeight);
        $drawWidth = max(1.0, round($sourceWidth * $scale, 2));
        $drawHeight = max(1.0, round($sourceHeight * $scale, 2));
        $drawX = $x + (($width - $drawWidth) / 2);
        $drawY = $y + (($height - $drawHeight) / 2);

        $pdf->Image($path, $drawX, $drawY, $drawWidth, $drawHeight);
    }

    private function signaturePath(?ApprovalStep $step): ?string
    {
        $relativePath = $step?->signature?->signature_image;

        if (!$relativePath) {
            return null;
        }

        $path = Storage::disk('public')->path($relativePath);

        return $this->signatureImageProcessor->normalizedAbsolutePath($path);
    }

    private function resolveFormValue(
        array $data,
        array $fields,
        array $preferredIds,
        array $labelKeywords,
        mixed $fallback = null
    ): mixed {
        foreach ($preferredIds as $id) {
            if (array_key_exists($id, $data) && $data[$id] !== null && $data[$id] !== '') {
                return $data[$id];
            }
        }

        foreach ($fields as $field) {
            $fieldId = $field['id'] ?? null;
            $label = strtolower((string) ($field['label'] ?? ''));

            if (!$fieldId || !array_key_exists($fieldId, $data)) {
                continue;
            }

            foreach ($labelKeywords as $keyword) {
                if (str_contains($label, strtolower($keyword)) && $data[$fieldId] !== null && $data[$fieldId] !== '') {
                    return $data[$fieldId];
                }
            }
        }

        return $fallback;
    }

    private function prefixValue(mixed $value, string $prefix): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $prefix . $value;
    }

    private function formatIndonesianDate(mixed $value, mixed $fallback = null): string
    {
        $source = $value ?: $fallback;

        try {
            $date = Carbon::parse($source);
        } catch (\Throwable $exception) {
            return is_string($source) ? $source : '-';
        }

        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $month = $months[(int) $date->format('n')] ?? $date->format('F');

        return $date->format('d') . ' ' . $month . ' ' . $date->format('Y');
    }

    private function formatCurrency(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function encodeText(string $text): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $text);
    }

    private function templatePdfPath(): string
    {
        return dirname(base_path()) . '/Template Form Requisition.pdf';
    }

    private function pdfLayout(): array
    {
        return [
            'total' => [
                'x' => 479.8,
                'y' => 411.9,
                'width' => 54.0,
                'size' => 8.7,
            ],
            'signatures' => [
                'prepared' => [322.0, 468.0, 60.0, 12.0],
                'checked' => [446.0, 471.0, 50.0, 12.0],
                'approved' => [322.0, 525.0, 60.0, 12.0],
            ],
            'names' => [
                'prepared' => [
                    'x' => 309.0,
                    'y' => 500.6,
                    'width' => 92.0,
                    'size' => 7.0,
                    'min_size' => 5.9,
                    'align' => 'C',
                    'style' => 'B',
                ],
                'checked' => [
                    'x' => 428.0,
                    'y' => 500.6,
                    'width' => 92.0,
                    'size' => 7.0,
                    'min_size' => 5.9,
                    'align' => 'C',
                    'style' => 'B',
                ],
                'approved' => [
                    'x' => 309.0,
                    'y' => 555.0,
                    'width' => 108.0,
                    'size' => 7.0,
                    'min_size' => 5.9,
                    'align' => 'C',
                    'style' => 'B',
                ],
            ],
        ];
    }
}
