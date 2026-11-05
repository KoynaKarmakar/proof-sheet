<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal dependency-free .xlsx writer (Office Open XML + ZipArchive).
 * Supports: multiple sheets, strings/numbers, a few styles, column widths,
 * frozen header row and autofilter. Enough for clean, readable exports.
 */
final class XlsxWriter
{
    // Style ids (index into cellXfs below)
    public const DEFAULT = 0;
    public const HEADER = 1;
    public const WRAP = 2;
    public const NUMBER = 3;
    public const CRITICAL = 4;
    public const WARNING = 5;
    public const INFO = 6;
    public const LABEL = 7;
    public const TITLE = 8;

    /** @var array<int, array{name:string, rows:array, widths:array, freeze:bool, filter:bool}> */
    private array $sheets = [];

    /**
     * @param list<list<array{v:mixed, s?:int}|string|int|float|null>> $rows
     * @param list<float> $widths column widths in characters
     */
    public function addSheet(string $name, array $rows, array $widths = [], bool $freezeHeader = true, bool $autoFilter = true): void
    {
        $this->sheets[] = [
            'name' => $this->uniqueName($name),
            'rows' => $rows,
            'widths' => $widths,
            'freeze' => $freezeHeader,
            'filter' => $autoFilter,
        ];
    }

    public function save(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create xlsx');
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes($n));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($n));
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $s) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($s));
        }
        $zip->close();
    }

    public function toString(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    // ------------------------------------------------------------------ xml

    private function sheetXml(array $s): string
    {
        $rowsXml = '';
        $maxCols = 0;
        foreach ($s['rows'] as $ri => $row) {
            $r = $ri + 1;
            $cells = '';
            foreach (array_values($row) as $ci => $cell) {
                $maxCols = max($maxCols, $ci + 1);
                if (!is_array($cell)) {
                    $cell = ['v' => $cell];
                }
                $v = $cell['v'] ?? null;
                $style = $cell['s'] ?? self::DEFAULT;
                $ref = self::col($ci) . $r;
                if ($v === null || $v === '') {
                    if ($style !== self::DEFAULT) {
                        $cells .= "<c r=\"$ref\" s=\"$style\"/>";
                    }
                    continue;
                }
                if (is_int($v) || is_float($v)) {
                    $num = is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : (string) $v;
                    $cells .= "<c r=\"$ref\" s=\"$style\"><v>$num</v></c>";
                } else {
                    $text = $this->esc((string) $v);
                    $cells .= "<c r=\"$ref\" s=\"$style\" t=\"inlineStr\"><is><t xml:space=\"preserve\">$text</t></is></c>";
                }
            }
            $rowsXml .= "<row r=\"$r\">$cells</row>";
        }

        $cols = '';
        if ($s['widths']) {
            $cols = '<cols>';
            foreach ($s['widths'] as $i => $w) {
                $n = $i + 1;
                $cols .= "<col min=\"$n\" max=\"$n\" width=\"" . max(6, (float) $w) . "\" customWidth=\"1\"/>";
            }
            $cols .= '</cols>';
        }

        $views = '<sheetViews><sheetView workbookViewId="0"'
            . ($s['freeze'] && count($s['rows']) > 1 ? '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView>' : '/>')
            . '</sheetViews>';
        $filter = ($s['filter'] && count($s['rows']) > 1 && $maxCols > 0)
            ? '<autoFilter ref="A1:' . self::col($maxCols - 1) . count($s['rows']) . '"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $views . '<sheetFormatPr defaultRowHeight="15"/>' . $cols
            . "<sheetData>$rowsXml</sheetData>" . $filter
            . '</worksheet>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF1F2A44"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="6">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F2A44"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFDE2E1"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1D6"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE3EEFB"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FFD0D5DD"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top"/></xf>'                                   // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" wrapText="1"/></xf>' // 1 header
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 2 wrap
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>'  // 3 number
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFill="1" applyFont="1"><alignment vertical="top" wrapText="1"/></xf>' // 4 critical
            . '<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFill="1" applyFont="1"><alignment vertical="top" wrapText="1"/></xf>' // 5 warning
            . '<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1"><alignment vertical="top" wrapText="1"/></xf>'               // 6 info
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>' // 7 label
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                   // 8 title
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $sheets .= '<sheet name="' . $this->esc($s['name']) . "\" sheetId=\"$id\" r:id=\"rId$id\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>$sheets</sheets></workbook>";
    }

    private function workbookRels(int $n): string
    {
        $x = '';
        for ($i = 1; $i <= $n; $i++) {
            $x .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
        }
        $s = $n + 1;
        $x .= "<Relationship Id=\"rId$s\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">$x</Relationships>";
    }

    private function contentTypes(int $n): string
    {
        $o = '';
        for ($i = 1; $i <= $n; $i++) {
            $o .= "<Override PartName=\"/xl/worksheets/sheet$i.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $o . '</Types>';
    }

    // -------------------------------------------------------------- helpers

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private function esc(string $s): string
    {
        // strip characters that are illegal in XML 1.0
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function uniqueName(string $name): string
    {
        $base = trim((string) preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $name)) ?: 'Sheet';
        $base = mb_substr($base, 0, 31);
        $taken = array_map(fn($s) => mb_strtolower($s['name']), $this->sheets);
        $candidate = $base;
        for ($i = 2; in_array(mb_strtolower($candidate), $taken, true); $i++) {
            $suffix = " ($i)";
            $candidate = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        return $candidate;
    }
}
