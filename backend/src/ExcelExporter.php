<?php
declare(strict_types=1);

namespace App;

/**
 * Turns a processed extraction into a workbook:
 *   Summary | Fields | one sheet per table | Flags | Text
 * Values are written exactly as printed; a separate "Numeric value" column
 * holds the parsed number so the sheet is usable for formulas.
 */
final class ExcelExporter
{
    public static function build(array $doc): string
    {
        $x = $doc['extraction'] ?? [];
        $w = new XlsxWriter();
        $H = XlsxWriter::HEADER;

        // ---------------------------------------------------------- Summary
        $counts = $x['flag_counts'] ?? ['critical' => 0, 'warning' => 0, 'info' => 0];
        $summary = [
            [['v' => $x['title'] ?? $doc['filename'] ?? 'Document', 's' => XlsxWriter::TITLE]],
            [],
            [['v' => 'Source file', 's' => XlsxWriter::LABEL], ['v' => $doc['filename'] ?? '', 's' => XlsxWriter::WRAP]],
            [['v' => 'Document type', 's' => XlsxWriter::LABEL], ['v' => self::human($x['document_type'] ?? ''), 's' => XlsxWriter::WRAP]],
            [['v' => 'Issuer', 's' => XlsxWriter::LABEL], ['v' => $x['issuer'] ?? 'Not available', 's' => XlsxWriter::WRAP]],
            [['v' => 'Pages', 's' => XlsxWriter::LABEL], ['v' => $x['page_count'] ?? 'Not available', 's' => XlsxWriter::WRAP]],
            [['v' => 'Summary', 's' => XlsxWriter::LABEL], ['v' => $x['summary'] ?? '', 's' => XlsxWriter::WRAP]],
            [['v' => 'Processed at', 's' => XlsxWriter::LABEL], ['v' => $doc['processed_at'] ?? '', 's' => XlsxWriter::WRAP]],
            [['v' => 'Model', 's' => XlsxWriter::LABEL], ['v' => $doc['model'] ?? '', 's' => XlsxWriter::WRAP]],
            [],
            [['v' => 'Flags', 's' => XlsxWriter::LABEL], ['v' => sprintf('%d critical, %d warning, %d info - see the Flags sheet',
                $counts['critical'] ?? 0, $counts['warning'] ?? 0, $counts['info'] ?? 0), 's' => ($counts['critical'] ?? 0) > 0 ? XlsxWriter::CRITICAL : XlsxWriter::WRAP]],
            [['v' => 'Note', 's' => XlsxWriter::LABEL], ['v' => 'Values are copied as printed. "Not available" means the value is not printed in the document. Nothing in this workbook is estimated.', 's' => XlsxWriter::WRAP]],
        ];
        $w->addSheet('Summary', $summary, [22, 90], false, false);

        // ----------------------------------------------------------- Fields
        $rows = [[
            ['v' => 'Group', 's' => $H], ['v' => 'Field', 's' => $H], ['v' => 'Value (as printed)', 's' => $H],
            ['v' => 'Numeric value', 's' => $H], ['v' => 'Type', 's' => $H], ['v' => 'Confidence', 's' => $H],
            ['v' => 'Page', 's' => $H], ['v' => 'Source text', 's' => $H],
        ]];
        foreach ($x['fields'] ?? [] as $f) {
            $conf = $f['confidence'] ?? '';
            $rows[] = [
                ['v' => $f['group'] ?? '', 's' => XlsxWriter::WRAP],
                ['v' => $f['label'], 's' => XlsxWriter::LABEL],
                ['v' => $f['value'] ?? 'Not available', 's' => $f['value'] === null ? XlsxWriter::WARNING : XlsxWriter::WRAP],
                $f['numeric'] !== null ? ['v' => (float) $f['numeric'], 's' => XlsxWriter::NUMBER] : ['v' => null, 's' => XlsxWriter::WRAP],
                ['v' => $f['value_type'] ?? '', 's' => XlsxWriter::WRAP],
                ['v' => $conf, 's' => $conf === 'low' ? XlsxWriter::WARNING : XlsxWriter::WRAP],
                ['v' => $f['page'] ?? null, 's' => XlsxWriter::WRAP],
                ['v' => $f['source_text'] ?? '', 's' => XlsxWriter::WRAP],
            ];
        }
        $w->addSheet('Fields', $rows, [18, 28, 34, 16, 12, 12, 7, 40]);

        // ----------------------------------------------------------- Tables
        foreach ($x['tables'] ?? [] as $i => $t) {
            $numeric = array_flip($t['numeric_columns'] ?? []);
            $hdr = array_map(fn($c) => ['v' => $c, 's' => $H], $t['columns']);
            $rows = [$hdr];
            foreach ($t['rows'] as $r) {
                $line = [];
                foreach ($r as $ci => $cell) {
                    $num = isset($numeric[$ci]) ? Validator::parseNumber($cell) : null;
                    $line[] = $num !== null ? ['v' => $num, 's' => XlsxWriter::NUMBER] : ['v' => $cell, 's' => XlsxWriter::WRAP];
                }
                $rows[] = $line;
            }
            $widths = [];
            foreach ($t['columns'] as $ci => $c) {
                $max = mb_strlen($c);
                foreach (array_slice($t['rows'], 0, 200) as $r) {
                    $max = max($max, mb_strlen((string) ($r[$ci] ?? '')));
                }
                $widths[] = min(60, $max + 3);
            }
            $w->addSheet('T' . ($i + 1) . ' ' . $t['title'], $rows, $widths);
        }

        // ------------------------------------------------------------ Flags
        $rows = [[['v' => 'Severity', 's' => $H], ['v' => 'Field / area', 's' => $H], ['v' => 'Message', 's' => $H], ['v' => 'Raised by', 's' => $H]]];
        foreach ($x['flags'] ?? [] as $f) {
            $s = ['critical' => XlsxWriter::CRITICAL, 'warning' => XlsxWriter::WARNING, 'info' => XlsxWriter::INFO][$f['severity']] ?? XlsxWriter::WRAP;
            $rows[] = [
                ['v' => strtoupper($f['severity']), 's' => $s],
                ['v' => $f['field'] ?? '', 's' => XlsxWriter::WRAP],
                ['v' => $f['message'], 's' => XlsxWriter::WRAP],
                ['v' => ($f['source'] ?? '') === 'validator' ? 'Rule check' : 'AI reader', 's' => XlsxWriter::WRAP],
            ];
        }
        $w->addSheet('Flags', $rows, [11, 28, 80, 12]);

        // ------------------------------------------------------------- Text
        $rows = [[['v' => 'Section', 's' => $H], ['v' => 'Content', 's' => $H]]];
        foreach ($x['sections'] ?? [] as $s) {
            $rows[] = [['v' => $s['heading'], 's' => XlsxWriter::LABEL], ['v' => self::plain($s['body_markdown']), 's' => XlsxWriter::WRAP]];
        }
        $w->addSheet('Text', $rows, [28, 110], true, false);

        return $w->toString();
    }

    private static function human(string $s): string
    {
        return ucwords(str_replace('_', ' ', $s));
    }

    /** Markdown -> readable plain text for a spreadsheet cell. */
    private static function plain(string $md): string
    {
        $t = preg_replace('/^#{1,6}\s*/m', '', $md);
        $t = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '$1$2', (string) $t);
        $t = preg_replace('/^\s*[-*]\s+/m', '• ', (string) $t);
        return mb_substr(trim((string) $t), 0, 32000);
    }
}
