@php
    $imageToDataUri = function (?string $path): ?string {
        if (!$path || !file_exists($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    };

    $signatureToDataUri = function ($signature) use ($imageToDataUri): ?string {
        if (!$signature || !$signature->signature_image) {
            return null;
        }

        $path = \Illuminate\Support\Facades\Storage::disk('public')->path($signature->signature_image);

        return $imageToDataUri($path);
    };

    $resolveFormValue = function (array $data, array $fields, array $preferredIds, array $labelKeywords, mixed $fallback = null): mixed {
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
    };

    $valueOrNull = function (mixed $value, string $prefix = ''): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        return $prefix . $value;
    };

    $formatDate = function (mixed $value, string $fallback = '-'): string {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return \Carbon\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $exception) {
            return is_string($value) ? $value : $fallback;
        }
    };

    $formatLongDate = function (mixed $value, string $fallback = '-'): string {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return \Carbon\Carbon::parse($value)->locale('id')->translatedFormat('d F Y');
        } catch (\Throwable $exception) {
            return is_string($value) ? $value : $fallback;
        }
    };

    $formatCurrencyIdr = fn (float $value): string => number_format($value, 0, ',', '.');

    $formData = $submission->form_data ?? [];
    $formFields = $submission->resolvedFormConfig()['fields'] ?? [];
    $approvalSteps = $submission->approvalSteps->sortBy('step_number')->values();
    $itStep = $approvalSteps->firstWhere('approver_role', 'IT Staff');
    $directorStep = $approvalSteps->firstWhere('approver_role', 'Operational Director');
    $accountingStep = $approvalSteps->where('approver_role', 'Accounting')->last();
    $requestDate = $resolveFormValue($formData, $formFields, ['request_date'], ['tanggal pengajuan', 'tanggal request'], optional($submission->created_at)->toDateString());
    $requiredDate = $resolveFormValue($formData, $formFields, ['needed_by_date'], ['required date', 'dibutuhkan sebelum'], $requestDate);
    $paymentTerms = $resolveFormValue($formData, $formFields, ['payment_terms'], ['payment terms', 'metode pembayaran'], 'Transfer');
    $quantity = (float) $resolveFormValue($formData, $formFields, ['quantity'], ['jumlah', 'qty'], 0);
    $amount = (float) $resolveFormValue($formData, $formFields, ['estimated_cost'], ['estimasi biaya', 'harga', 'biaya'], 0);
    $pricePerUnit = $quantity > 0 ? $amount / $quantity : $amount;
    $requesterName = $resolveFormValue($formData, $formFields, ['employee_name'], ['nama pemohon', 'nama requester', 'request by'], $submission->user?->name ?? '-');
    $requesterSection = $resolveFormValue($formData, $formFields, ['department'], ['departemen', 'section'], $submission->user?->department ?? '-');
    $requestedPosition = $resolveFormValue($formData, $formFields, ['position'], ['jabatan', 'position'], null);
    $requesterTitle = $submission->user?->roles
        ?->pluck('name')
        ?->reject(fn (string $role) => $role === 'Admin')
        ?->implode(', ') ?: 'Employee';
    $requesterTitle = $requestedPosition ?: $requesterTitle;
    $requestByLine = trim(implode(' / ', array_filter([$requesterName, $requesterSection])));
    $headerRequester = $requestedPosition
        ?: ($requesterSection ?: $requesterTitle ?: $requesterName);
    $itemName = (string) $resolveFormValue(
        $formData,
        $formFields,
        ['item_name'],
        ['nama barang', 'barang apa', 'barang'],
        $resolveFormValue($formData, $formFields, ['item_type'], ['tipe barang', 'jenis barang', 'jenis'], '')
    );
    $specificationLines = array_filter([
        $resolveFormValue($formData, $formFields, ['specifications'], ['spesifikasi'], null),
        $valueOrNull($resolveFormValue($formData, $formFields, ['reason'], ['alasan'], null), 'Alasan: '),
        $valueOrNull($resolveFormValue($formData, $formFields, ['urgency'], ['urgensi', 'priority'], null), 'Urgensi: '),
        $valueOrNull($resolveFormValue($formData, $formFields, ['vendor_preference'], ['vendor', 'referensi'], null), 'Referensi: '),
    ]);
    $specificationText = implode("\n", $specificationLines);

    $templateDataUri = $imageToDataUri($templateImagePath);
    $itSignature = $signatureToDataUri(optional($itStep)->signature);
    $directorSignature = $signatureToDataUri(optional($directorStep)->signature);
    $accountingSignature = $signatureToDataUri(optional($accountingStep)->signature);

    $itName = optional(optional($itStep)->approver)->name ?? '';
    $directorName = optional(optional($directorStep)->approver)->name ?? '';
    $accountingName = optional(optional($accountingStep)->approver)->name ?? '';

    $displayRequestDate = $formatLongDate($requestDate, $formatDate($submission->created_at));
    $displayRequiredDate = $formatLongDate($requiredDate, $displayRequestDate);
    $displayQuantity = $quantity > 0 ? number_format($quantity, 0, ',', '.') : '';
    $displayPrice = $amount > 0 ? $formatCurrencyIdr($pricePerUnit) : '';
    $displayAmount = $amount > 0 ? $formatCurrencyIdr($amount) : '';
    $displayTotal = $amount > 0 ? $formatCurrencyIdr($amount) : '';
    $approvedStep = $approvalSteps
        ->filter(fn ($step) => in_array($step->approver_role, ['Operational Director', 'Accounting'], true))
        ->sortByDesc('step_number')
        ->first(fn ($step) => $step->signature_id !== null || filled(optional($step->approver)->name))
        ?? $directorStep
        ?? $accountingStep;
    $layout = $layout ?? [
        'total' => [
            'x' => 479.8,
            'y' => 411.9,
            'width' => 54.0,
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
            ],
            'checked' => [
                'x' => 428.0,
                'y' => 500.6,
                'width' => 92.0,
                'size' => 7.0,
            ],
            'approved' => [
                'x' => 309.0,
                'y' => 555.0,
                'width' => 108.0,
                'size' => 7.0,
            ],
        ],
    ];
    $preparedName = trim((string) $requesterName);
    $checkedName = $itName;
    $approvedName = optional(optional($approvedStep)->approver)->name ?? '';
    $approvedSignature = $signatureToDataUri(optional($approvedStep)->signature);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>GESIT Requisition</title>
    <style>
        @page {
            margin: 0;
            size: 595pt 842pt;
        }

        body {
            margin: 0;
            font-family: "Courier New", Courier, monospace;
            font-size: 8pt;
            color: #111827;
        }

        .page {
            position: relative;
            width: 595pt;
            height: 842pt;
            overflow: hidden;
        }

        .template-bg {
            position: absolute;
            inset: 0;
            width: 595pt;
            height: 842pt;
            z-index: 0;
        }

        .field {
            position: absolute;
            z-index: 2;
            line-height: 1.12;
            white-space: pre-wrap;
            word-break: break-word;
            overflow: hidden;
        }

        .mask {
            background: #fff;
            padding: 0 1pt;
        }

        .value-bold {
            font-weight: 700;
        }

        .value-sm {
            font-size: 7.3pt;
        }

        .value-md {
            font-size: 8pt;
        }

        .value-lg {
            font-size: 8.4pt;
        }

        .align-center {
            text-align: center;
        }

        .align-right {
            text-align: right;
        }

        .signature {
            position: absolute;
            z-index: 2;
            object-fit: contain;
            overflow: hidden;
        }

        .name-line {
            position: absolute;
            z-index: 2;
            font-weight: 700;
            text-align: center;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="page">
        @if($templateDataUri)
            <img class="template-bg" src="{{ $templateDataUri }}" alt="Template">
        @endif

        <div class="field mask value-sm" style="left: 397.4pt; top: 110.6pt; width: 82pt;">{{ $displayRequestDate }}</div>

        <div class="field mask value-md" style="left: 310.1pt; top: 157.5pt; width: 126pt;">{{ $headerRequester }}</div>
        <div class="field mask value-md" style="left: 310.1pt; top: 168.6pt; width: 114pt;">{{ $displayRequiredDate }}</div>

        @if(strtolower(trim((string) $paymentTerms)) !== 'transfer')
            <div class="field mask value-md" style="left: 310.1pt; top: 179.7pt; width: 76pt;">{{ $paymentTerms }}</div>
        @endif

        <div class="field mask value-bold value-lg" style="left: 56.9pt; top: 218.2pt; width: 204pt; min-height: 12pt;">{{ $itemName }}</div>
        <div class="field mask value-sm" style="left: 51.8pt; top: 248.6pt; width: 244pt; height: 118pt; line-height: 1.17;">{{ $specificationText }}</div>

        <div class="field mask value-md align-center" style="left: 438.2pt; top: 218.2pt; width: 10pt;">{{ $displayQuantity }}</div>
        <div class="field mask value-md" style="left: 481.2pt; top: 218.2pt; width: 48pt;">{{ $displayPrice }}</div>
        <div class="field mask value-md" style="left: 364.1pt; top: 218.2pt; width: 48pt;">{{ $displayAmount }}</div>

        <div class="field mask value-md value-bold align-right" style="left: {{ $layout['total']['x'] }}pt; top: {{ $layout['total']['y'] }}pt; width: {{ $layout['total']['width'] }}pt;">{{ $displayTotal }}</div>

        @if($itSignature)
            <img class="signature" src="{{ $itSignature }}" alt="Checked signature" style="left: {{ $layout['signatures']['checked'][0] }}pt; top: {{ $layout['signatures']['checked'][1] }}pt; width: {{ $layout['signatures']['checked'][2] }}pt; height: {{ $layout['signatures']['checked'][3] }}pt;">
        @endif
        @if($approvedSignature)
            <img class="signature" src="{{ $approvedSignature }}" alt="Approved signature" style="left: {{ $layout['signatures']['approved'][0] }}pt; top: {{ $layout['signatures']['approved'][1] }}pt; width: {{ $layout['signatures']['approved'][2] }}pt; height: {{ $layout['signatures']['approved'][3] }}pt;">
        @endif

        <div class="name-line" style="left: {{ $layout['names']['prepared']['x'] }}pt; top: {{ $layout['names']['prepared']['y'] }}pt; width: {{ $layout['names']['prepared']['width'] }}pt; font-size: {{ $layout['names']['prepared']['size'] }}pt;">{{ $preparedName }}</div>
        <div class="name-line" style="left: {{ $layout['names']['checked']['x'] }}pt; top: {{ $layout['names']['checked']['y'] }}pt; width: {{ $layout['names']['checked']['width'] }}pt; font-size: {{ $layout['names']['checked']['size'] }}pt;">{{ $checkedName }}</div>
        <div class="name-line" style="left: {{ $layout['names']['approved']['x'] }}pt; top: {{ $layout['names']['approved']['y'] }}pt; width: {{ $layout['names']['approved']['width'] }}pt; font-size: {{ $layout['names']['approved']['size'] }}pt;">{{ $approvedName }}</div>
    </div>
</body>
</html>
