<?php

namespace App\Helpers;

/**
 * Minimal XLSX reader.
 * Reads an XLSX file and returns rows as arrays of strings.
 * Requires PHP's ZipArchive and SimpleXML.
 */
class XlsxParser
{
    /**
     * Parse sheet1 of an uploaded XLSX file.
     *
     * @param  \Illuminate\Http\UploadedFile $file
     * @param  int   $skipRows  number of header rows to skip
     * @return array  [ [col0, col1, ...], ... ]
     */
    public static function parse($file, int $skipRows = 0): array
    {
        return self::parseSheet($file, null, $skipRows);
    }

    /**
     * Parse a specific sheet by name (or sheet1 if null) from an uploaded XLSX.
     *
     * @param  \Illuminate\Http\UploadedFile|string $file  uploaded file or path string
     * @param  string|null $sheetName  exact sheet name; null = first sheet
     * @param  int         $skipRows   header rows to skip (1-based: skip rows 1..skipRows)
     * @return array  [ [col0, col1, ...], ... ]
     */
    public static function parseSheet($file, ?string $sheetName = null, int $skipRows = 0): array
    {
        $path = is_string($file) ? $file : $file->getRealPath();
        $zip  = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('ไม่สามารถเปิดไฟล์ XLSX ได้');
        }

        // Read shared strings
        $shared = [];
        $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = simplexml_load_string($ssXml);
            foreach (($ss->xpath('//*[local-name()="si"]') ?: []) as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string) $r->t;
                    }
                    $shared[] = $text;
                }
            }
        }

        // Resolve which worksheet file to use
        $worksheetPath = self::resolveSheetPath($zip, $sheetName);
        $sheetXml = $zip->getFromName($worksheetPath);
        $zip->close();

        if (!$sheetXml) {
            throw new \RuntimeException("ไม่พบ sheet '{$sheetName}' ในไฟล์ XLSX");
        }

        return self::parseSheetXml($sheetXml, $shared, $skipRows);
    }

    /**
     * List all sheet names in an XLSX file.
     */
   public static function sheetNames($file): array
{
    $path = is_string($file) ? $file : $file->getRealPath();

    $zip = new \ZipArchive();

    if ($zip->open($path) !== true) {
        throw new \RuntimeException('ไม่สามารถเปิดไฟล์ XLSX ได้');
    }

    $wbXml = $zip->getFromName('xl/workbook.xml');
    $zip->close();

    if (!$wbXml) {
        throw new \RuntimeException('ไม่พบ workbook.xml');
    }

    $wb = simplexml_load_string($wbXml);

    if ($wb === false) {
        throw new \RuntimeException('อ่าน workbook.xml ไม่สำเร็จ');
    }

    $sheets = $wb->xpath('//*[local-name()="sheet"]');

    if (empty($sheets)) {
        throw new \RuntimeException('ไม่พบรายชื่อชีตใน Excel');
    }

    $names = [];

    foreach ($sheets as $sheet) {
        $names[] = (string) $sheet['name'];
    }

    return $names;
}


    // ── private helpers ──────────────────────────────────────


private static function resolveSheetPath(\ZipArchive $zip, ?string $sheetName): string
{
    $wbXml = $zip->getFromName('xl/workbook.xml');

    if (!$wbXml) {
        throw new \RuntimeException('ไม่พบ workbook.xml');
    }

    $wb = simplexml_load_string($wbXml);

    if ($wb === false) {
        throw new \RuntimeException('อ่าน workbook.xml ไม่สำเร็จ');
    }

    $sheets = $wb->xpath('//*[local-name()="sheet"]') ?: [];

    $rId = null;

    foreach ($sheets as $sheet) {
        if ($sheetName === null || (string) $sheet['name'] === $sheetName) {
            $attrs = $sheet->attributes(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
            );

            $rId = (string) $attrs['id'];
            break;
        }
    }

    if (!$rId) {
        throw new \RuntimeException("ไม่พบชีต '{$sheetName}'");
    }

    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if (!$relsXml) {
        throw new \RuntimeException('ไม่พบ workbook relationships');
    }

    $rels = simplexml_load_string($relsXml);

    if ($rels === false) {
        throw new \RuntimeException('อ่าน workbook relationships ไม่สำเร็จ');
    }

    $relationships = $rels->xpath('//*[local-name()="Relationship"]') ?: [];

    foreach ($relationships as $rel) {
        if ((string) $rel['Id'] === $rId) {
            $target = (string) $rel['Target'];

            if (str_starts_with($target, '/')) {
                return ltrim($target, '/');
            }

            return 'xl/' . ltrim($target, '/');
        }
    }

    throw new \RuntimeException("ไม่พบ worksheet สำหรับชีต '{$sheetName}'");
}


private static function parseSheetXml(string $xml, array $shared, int $skipRows): array
{
    $sheet = simplexml_load_string($xml);

    if ($sheet === false) {
        throw new \RuntimeException('อ่าน worksheet XML ไม่สำเร็จ');
    }

    $rowNodes = $sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');

    if ($rowNodes === false) {
        throw new \RuntimeException('อ่านแถวข้อมูล Excel ไม่สำเร็จ');
    }

    $rows = [];

    foreach ($rowNodes as $row) {
        $rowNum = (int) $row['r'];

        if ($rowNum <= $skipRows) {
            continue;
        }

        $cells = [];
        $maxCol = 0;

        $cellNodes = $row->xpath('./*[local-name()="c"]') ?: [];

foreach ($cellNodes as $c) {
            $ref = (string) $c['r'];
            $col = self::colIndex($ref);
            $type = (string) $c['t'];

            $values = $c->xpath('./*[local-name()="v"]');
            $val = isset($values[0]) ? (string) $values[0] : '';

            if ($type === 's') {
                $val = $shared[(int) $val] ?? '';
            } elseif ($type === 'inlineStr') {
                $texts = $c->xpath('.//*[local-name()="t"]');
                $val = '';

               foreach (($texts ?: []) as $text) {
                    $val .= (string) $text;
                }
            }

            $cells[$col] = $val;
            $maxCol = max($maxCol, $col);
        }

        $result = [];

        for ($i = 0; $i <= max($maxCol, 10); $i++) {
            $result[] = $cells[$i] ?? '';
        }

        $rows[] = $result;
    }

    return $rows;
}


    /**
     * Convert a cell reference like "AB6" to a 0-based column index.
     */
    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
        $letters = $m[1] ?? 'A';
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
