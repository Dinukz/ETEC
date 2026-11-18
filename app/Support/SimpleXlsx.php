<?php

namespace App\Support;

/**
 * A one-sheet .xlsx file, built without a spreadsheet library.
 *
 * Written for the Payment Summary bank deposit list (2026-09-17: "PDF eke tikama
 * Excel ekat enn one"). A CSV was not good enough for that list: Excel reads an
 * NIC like 200029502390 or an account number like 0012345 as a NUMBER, shows
 * 2.00E+11 and drops the leading zero — and a bank deposit made from that sheet
 * goes to the wrong account. Here every cell is written as TEXT unless the caller
 * passes a real int/float, so what the PDF shows is exactly what Excel shows.
 *
 * Needs PHP's zip extension. Call available() first; the caller falls back to a
 * CSV when it is missing rather than failing the download.
 */
class SimpleXlsx
{
    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows  int/float cells are numbers, everything else text
     * @return string the .xlsx file's bytes
     */
    public static function build(string $sheetName, array $header, iterable $rows): string
    {
        $lines = [self::row(1, $header, bold: true)];
        $r = 1;
        $widths = array_map(fn ($h) => mb_strlen((string) $h), $header);

        foreach ($rows as $row) {
            $row = array_values((array) $row);
            $lines[] = self::row(++$r, $row);

            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $cols = '';
        foreach ($widths as $i => $w) {
            $n = $i + 1;
            $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.min(60, $w + 3).'" customWidth="1"/>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.implode('', $lines).'</sheetData>'
            .'</worksheet>';

        $name = self::xml(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName), 0, 31));

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            // Style 0 = normal, 1 = bold header, 2 = number with 2 decimals.
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3">'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                .'</cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Excel file.');
        }

        foreach ($files as $entry => $body) {
            $zip->addFromString($entry, $body);
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param  array<int, mixed>  $cells */
    protected static function row(int $r, array $cells, bool $bold = false): string
    {
        $out = '<row r="'.$r.'">';

        foreach (array_values($cells) as $i => $cell) {
            $ref = self::column($i).$r;

            if (! $bold && (is_int($cell) || is_float($cell))) {
                $out .= '<c r="'.$ref.'" s="2"><v>'.$cell.'</v></c>';

                continue;
            }

            $out .= '<c r="'.$ref.'" t="inlineStr"'.($bold ? ' s="1"' : '').'><is><t>'.self::xml((string) $cell).'</t></is></c>';
        }

        return $out.'</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    protected static function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    protected static function xml(string $text): string
    {
        // Strip control characters XML 1.0 forbids, then escape.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
