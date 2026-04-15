<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use RuntimeException;
use ZipArchive;

class ItActivityExcelExporter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(array $payload): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required for Excel export.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'it-activities-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for Excel export.');
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to create Excel archive.');
        }

        $logoPath = public_path('logoyulie.png');
        $hasLogo = is_file($logoPath) && is_readable($logoPath);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($hasLogo));
        $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml($payload['generated_at'] ?? now()));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($payload, $hasLogo));

        if ($hasLogo) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $this->worksheetRelationshipsXml());
            $zip->addFromString('xl/drawings/drawing1.xml', $this->drawingXml());
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->drawingRelationshipsXml());
            $zip->addFile($logoPath, 'xl/media/logo.png');
        }

        $zip->close();

        $binary = file_get_contents($temporaryPath);
        @unlink($temporaryPath);

        if ($binary === false) {
            throw new RuntimeException('Unable to read generated Excel archive.');
        }

        return $binary;
    }

    private function contentTypesXml(bool $hasLogo): string
    {
        $parts = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">',
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
        ];

        if ($hasLogo) {
            $parts[] = '<Default Extension="png" ContentType="image/png"/>';
        }

        $parts[] = '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $parts[] = '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $parts[] = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $parts[] = '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $parts[] = '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';

        if ($hasLogo) {
            $parts[] = '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }

        $parts[] = '</Types>';

        return implode('', $parts);
    }

    private function rootRelationshipsXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>',
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>',
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>',
            '</Relationships>',
        ]);
    }

    private function appPropertiesXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">',
            '<Application>GESIT</Application>',
            '<DocSecurity>0</DocSecurity>',
            '<ScaleCrop>false</ScaleCrop>',
            '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>',
            '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Aktivitas IT</vt:lpstr></vt:vector></TitlesOfParts>',
            '<Company>PT Yulie Sekuritas Indonesia Tbk.</Company>',
            '</Properties>',
        ]);
    }

    private function corePropertiesXml(CarbonInterface $generatedAt): string
    {
        $timestamp = $generatedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">',
            '<dc:title>Laporan Aktivitas IT</dc:title>',
            '<dc:creator>GESIT</dc:creator>',
            '<cp:lastModifiedBy>GESIT</cp:lastModifiedBy>',
            '<dcterms:created xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:created>',
            '<dcterms:modified xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:modified>',
            '</cp:coreProperties>',
        ]);
    }

    private function workbookXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
            '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>',
            '<sheets><sheet name="Aktivitas IT" sheetId="1" r:id="rId1"/></sheets>',
            '</workbook>',
        ]);
    }

    private function workbookRelationshipsXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>',
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
            '</Relationships>',
        ]);
    }

    private function stylesXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">',
            '<fonts count="4">',
            '<font><sz val="11"/><color rgb="FF1F2937"/><name val="Arial"/><family val="2"/></font>',
            '<font><b/><sz val="18"/><color rgb="FF7C5710"/><name val="Arial"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FF6B7280"/><name val="Arial"/><family val="2"/></font>',
            '<font><b/><sz val="11"/><color rgb="FF7C5710"/><name val="Arial"/><family val="2"/></font>',
            '</fonts>',
            '<fills count="4">',
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF9F3E7"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF3E6CA"/><bgColor indexed="64"/></patternFill></fill>',
            '</fills>',
            '<borders count="3">',
            '<border><left/><right/><top/><bottom/><diagonal/></border>',
            '<border><left style="thin"><color rgb="FFD7C29A"/></left><right style="thin"><color rgb="FFD7C29A"/></right><top style="thin"><color rgb="FFD7C29A"/></top><bottom style="thin"><color rgb="FFD7C29A"/></bottom><diagonal/></border>',
            '<border><left style="thin"><color rgb="FFD9E1EA"/></left><right style="thin"><color rgb="FFD9E1EA"/></right><top style="thin"><color rgb="FFD9E1EA"/></top><bottom style="thin"><color rgb="FFD9E1EA"/></bottom><diagonal/></border>',
            '</borders>',
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>',
            '<cellXfs count="7">',
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>',
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>',
            '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>',
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center"/></xf>',
            '<xf numFmtId="0" fontId="3" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>',
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>',
            '</cellXfs>',
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>',
            '</styleSheet>',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function worksheetXml(array $payload, bool $hasLogo): string
    {
        $activities = $payload['activities'] ?? [];
        $textStartColumn = $hasLogo ? 3 : 1;
        $titleMergeEnd = $hasLogo ? 9 : 7;

        $rows = [];
        $rows[] = $this->rowXml(1, [
            $this->stringCell($this->cellReference($textStartColumn, 1), 'Laporan Aktivitas IT', 1),
        ], 28);
        $rows[] = $this->rowXml(2, [
            $this->stringCell($this->cellReference($textStartColumn, 2), 'Dibuat pada '.$this->formatDateTime($payload['generated_at'] ?? now()), 2),
        ], 20);
        $rows[] = $this->rowXml(3, [
            $this->stringCell($this->cellReference($textStartColumn, 3), (string) ($payload['filter_summary'] ?? ''), 2),
        ], 20);
        $rows[] = $this->rowXml(5, [
            $this->stringCell('A5', 'Total Aktivitas', 3),
            $this->numberCell('B5', (int) ($payload['stats']['total'] ?? 0), 4),
            $this->stringCell('C5', 'Helpdesk', 3),
            $this->numberCell('D5', (int) ($payload['stats']['helpdesk'] ?? 0), 4),
        ], 22);
        $rows[] = $this->rowXml(6, [
            $this->stringCell('A6', 'Pengajuan', 3),
            $this->numberCell('B6', (int) ($payload['stats']['submission'] ?? 0), 4),
            $this->stringCell('C6', 'Internal IT', 3),
            $this->numberCell('D6', (int) ($payload['stats']['internal'] ?? 0), 4),
        ], 22);

        $headers = [
            'No',
            'Waktu',
            'Modul',
            'Aktivitas',
            'Referensi',
            'Objek',
            'Aktor',
            'Role Aktor',
            'Requester / Pemohon',
            'PIC IT',
            'Pihak Terkait',
            'Status Aktivitas',
            'Status Terkini',
            'Ringkasan',
            'Catatan',
            'Konteks',
            'Visibilitas',
        ];

        $headerCells = [];
        foreach ($headers as $index => $header) {
            $headerCells[] = $this->stringCell($this->cellReference($index + 1, 8), $header, 5);
        }
        $rows[] = $this->rowXml(8, $headerCells, 24);

        $currentRow = 9;
        foreach ($activities as $index => $activity) {
            $cells = [
                $this->numberCell('A'.$currentRow, $index + 1, 6),
                $this->stringCell('B'.$currentRow, $this->formatDateTime($activity['occurred_at'] ?? null), 6),
                $this->stringCell('C'.$currentRow, (string) ($activity['module_label'] ?? '-'), 6),
                $this->stringCell('D'.$currentRow, (string) ($activity['activity_name'] ?? '-'), 6),
                $this->stringCell('E'.$currentRow, (string) ($activity['reference_number'] ?? '-'), 6),
                $this->stringCell('F'.$currentRow, (string) ($activity['item_title'] ?? '-'), 6),
                $this->stringCell('G'.$currentRow, (string) ($activity['actor_name'] ?? '-'), 6),
                $this->stringCell('H'.$currentRow, (string) ($activity['actor_role'] ?? '-'), 6),
                $this->stringCell('I'.$currentRow, (string) ($activity['requester_name'] ?? '-'), 6),
                $this->stringCell('J'.$currentRow, (string) ($activity['it_owner'] ?? '-'), 6),
                $this->stringCell('K'.$currentRow, (string) ($activity['related_users'] ?? '-'), 6),
                $this->stringCell('L'.$currentRow, (string) ($activity['status_at_event_label'] ?? '-'), 6),
                $this->stringCell('M'.$currentRow, (string) ($activity['current_status_label'] ?? '-'), 6),
                $this->stringCell('N'.$currentRow, (string) ($activity['summary'] ?? '-'), 6),
                $this->stringCell('O'.$currentRow, (string) ($activity['notes'] ?? '-'), 6),
                $this->stringCell('P'.$currentRow, (string) ($activity['context_label'] ?? '-'), 6),
                $this->stringCell('Q'.$currentRow, (string) ($activity['visibility_label'] ?? '-'), 6),
            ];

            $rows[] = $this->rowXml($currentRow, $cells, 42);
            $currentRow++;
        }

        $lastRow = max($currentRow - 1, 8);
        $mergeCells = [
            'A1:B4',
            $this->cellReference($textStartColumn, 1).':'.$this->cellReference($titleMergeEnd, 1),
            $this->cellReference($textStartColumn, 2).':'.$this->cellReference($titleMergeEnd, 2),
            $this->cellReference($textStartColumn, 3).':'.$this->cellReference($titleMergeEnd, 3),
        ];

        $xml = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
            '<dimension ref="A1:Q'.$lastRow.'"/>',
            '<sheetViews><sheetView workbookViewId="0"><pane ySplit="8" topLeftCell="A9" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A9" sqref="A9"/></sheetView></sheetViews>',
            '<sheetFormatPr defaultRowHeight="18"/>',
            '<cols>',
            '<col min="1" max="1" width="7" customWidth="1"/>',
            '<col min="2" max="2" width="18" customWidth="1"/>',
            '<col min="3" max="3" width="12" customWidth="1"/>',
            '<col min="4" max="4" width="28" customWidth="1"/>',
            '<col min="5" max="5" width="16" customWidth="1"/>',
            '<col min="6" max="6" width="24" customWidth="1"/>',
            '<col min="7" max="8" width="18" customWidth="1"/>',
            '<col min="9" max="10" width="18" customWidth="1"/>',
            '<col min="11" max="11" width="22" customWidth="1"/>',
            '<col min="12" max="13" width="16" customWidth="1"/>',
            '<col min="14" max="15" width="28" customWidth="1"/>',
            '<col min="16" max="16" width="18" customWidth="1"/>',
            '<col min="17" max="17" width="14" customWidth="1"/>',
            '</cols>',
            '<sheetData>'.implode('', $rows).'</sheetData>',
            '<mergeCells count="'.count($mergeCells).'">',
            implode('', array_map(fn (string $reference) => '<mergeCell ref="'.$reference.'"/>', $mergeCells)),
            '</mergeCells>',
            '<autoFilter ref="A8:Q'.$lastRow.'"/>',
        ];

        if ($hasLogo) {
            $xml[] = '<drawing r:id="rId1"/>';
        }

        $xml[] = '</worksheet>';

        return implode('', $xml);
    }

    private function worksheetRelationshipsXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>',
            '</Relationships>',
        ]);
    }

    private function drawingXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
            '<xdr:twoCellAnchor editAs="oneCell">',
            '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>',
            '<xdr:to><xdr:col>1</xdr:col><xdr:colOff>381000</xdr:colOff><xdr:row>3</xdr:row><xdr:rowOff>95250</xdr:rowOff></xdr:to>',
            '<xdr:pic>',
            '<xdr:nvPicPr><xdr:cNvPr id="2" name="Yulie Sekuritas Logo"/><xdr:cNvPicPr/></xdr:nvPicPr>',
            '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>',
            '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1524000" cy="914400"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>',
            '</xdr:pic>',
            '<xdr:clientData/>',
            '</xdr:twoCellAnchor>',
            '</xdr:wsDr>',
        ]);
    }

    private function drawingRelationshipsXml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/>',
            '</Relationships>',
        ]);
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function rowXml(int $rowNumber, array $cells, ?float $height = null): string
    {
        $attributes = ['r="'.$rowNumber.'"'];

        if ($height !== null) {
            $attributes[] = 'ht="'.$height.'"';
            $attributes[] = 'customHeight="1"';
        }

        return '<row '.implode(' ', $attributes).'>'.implode('', $cells).'</row>';
    }

    private function stringCell(string $reference, string $value, int $styleId): string
    {
        return '<c r="'.$reference.'" s="'.$styleId.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape($value).'</t></is></c>';
    }

    private function numberCell(string $reference, int|float $value, int $styleId): string
    {
        return '<c r="'.$reference.'" s="'.$styleId.'"><v>'.$value.'</v></c>';
    }

    private function cellReference(int $columnNumber, int $rowNumber): string
    {
        return $this->columnLetter($columnNumber).$rowNumber;
    }

    private function columnLetter(int $columnNumber): string
    {
        $result = '';

        while ($columnNumber > 0) {
            $columnNumber--;
            $result = chr(65 + ($columnNumber % 26)).$result;
            $columnNumber = intdiv($columnNumber, 26);
        }

        return $result;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('d/m/y H.i');
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->format('d/m/y H.i');
        }

        return '-';
    }
}
