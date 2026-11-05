<?php
declare(strict_types=1);

namespace App;

/**
 * Deterministic post-processing of the model output:
 *  - cleans the structure so the frontend can trust it
 *  - parses printed numbers into machine values (parsing only, nothing derived)
 *  - adds rule-based flags (missing values, low confidence, arithmetic checks)
 *
 * Arithmetic checks only VERIFY printed figures against each other; computed
 * sums are never written back as extracted values.
 */
final class Validator
{
    private const TOL = 0.011;

    public static function process(array $x, array $meta = []): array
    {
        $flags = [];
        foreach (($x['flags'] ?? []) as $f) {
            if (!is_array($f) || empty($f['message'])) {
                continue;
            }
            $flags[] = [
                'severity' => in_array($f['severity'] ?? '', ['info', 'warning', 'critical'], true) ? $f['severity'] : 'warning',
                'field' => $f['field'] ?? null,
                'message' => (string) $f['message'],
                'source' => 'model',
            ];
        }

        if (($meta['stop_reason'] ?? null) === 'max_tokens') {
            $flags[] = self::flag('critical', null, 'The extraction hit the output limit and was cut off. Tables or fields may be incomplete. Raise LLM_MAX_TOKENS or split the document.');
        }

        // ---------------------------------------------------------- fields
        $fields = [];
        foreach (($x['fields'] ?? []) as $f) {
            if (!is_array($f) || empty($f['label'])) {
                continue;
            }
            $type = $f['value_type'] ?? 'text';
            $value = isset($f['value']) && $f['value'] !== '' ? (string) $f['value'] : null;
            $field = [
                'key' => $f['key'] ?? self::slug($f['label']),
                'label' => (string) $f['label'],
                'group' => $f['group'] ?? 'General',
                'value' => $value,
                'value_type' => $type,
                'currency' => $f['currency'] ?? null,
                'page' => $f['page'] ?? null,
                'confidence' => in_array($f['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $f['confidence'] : 'medium',
                'source_text' => $f['source_text'] ?? null,
                'numeric' => null,
            ];

            if ($value === null) {
                $flags[] = self::flag('info', $field['label'], 'Not available - not printed in the document.');
            } elseif (in_array($type, ['number', 'currency', 'percentage'], true)) {
                $field['numeric'] = self::parseNumber($value);
                if ($field['numeric'] === null) {
                    $flags[] = self::flag('warning', $field['label'], "Printed value \"$value\" could not be read as a number.");
                }
            } elseif ($type === 'date' && self::parseDate($value) === null) {
                $flags[] = self::flag('info', $field['label'], "Date \"$value\" is in an unusual format - check it.");
            }

            if ($value !== null && $field['confidence'] === 'low') {
                $flags[] = self::flag('warning', $field['label'], 'Low confidence reading - verify against the original.');
            }
            if ($value !== null && ($bad = self::checkIdentifierFormat($field['label'], $value)) !== null) {
                $flags[] = self::flag('critical', $field['label'], $bad);
            }
            $fields[] = $field;
        }

        // ---------------------------------------------------------- tables
        $tables = [];
        foreach (($x['tables'] ?? []) as $i => $t) {
            if (!is_array($t) || empty($t['columns'])) {
                continue;
            }
            $cols = array_map('strval', $t['columns']);
            $rows = [];
            $bad = 0;
            foreach (($t['rows'] ?? []) as $r) {
                if (!is_array($r)) {
                    continue;
                }
                if (count($r) !== count($cols)) {
                    $bad++;
                    $r = array_slice(array_pad($r, count($cols), null), 0, count($cols));
                }
                $rows[] = array_map(fn($c) => $c === null || $c === '' ? null : (string) $c, $r);
            }
            $title = (string) ($t['title'] ?? 'Table ' . ($i + 1));
            if ($bad > 0) {
                $flags[] = self::flag('warning', $title, "$bad row(s) did not match the column count and were padded/trimmed - check alignment.");
            }
            $tables[] = [
                'title' => $title,
                'page' => $t['page'] ?? null,
                'columns' => $cols,
                'rows' => $rows,
                'numeric_columns' => self::numericColumns($cols, $rows),
            ];
        }

        // ------------------------------------------------- arithmetic checks
        foreach ($tables as $t) {
            array_push($flags, ...self::checkRunningBalance($t, $fields));
        }
        array_push($flags, ...self::checkInvoiceTotal($fields));

        $sections = [];
        foreach (($x['sections'] ?? []) as $s) {
            if (is_array($s) && (($s['body_markdown'] ?? '') !== '' || ($s['heading'] ?? '') !== '')) {
                $sections[] = ['heading' => (string) ($s['heading'] ?? ''), 'body_markdown' => (string) ($s['body_markdown'] ?? '')];
            }
        }

        $counts = ['info' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($flags as $f) {
            $counts[$f['severity']]++;
        }
        usort($flags, fn($a, $b) => self::rank($b['severity']) <=> self::rank($a['severity']));

        return [
            'document_type' => (string) ($x['document_type'] ?? 'other'),
            'title' => (string) ($x['title'] ?? 'Untitled document'),
            'summary' => (string) ($x['summary'] ?? ''),
            'issuer' => $x['issuer'] ?? null,
            'page_count' => $x['page_count'] ?? null,
            'fields' => $fields,
            'tables' => $tables,
            'sections' => $sections,
            'flags' => $flags,
            'flag_counts' => $counts,
        ];
    }

    // ------------------------------------------------------------- checks

    /** Row-by-row: previous balance + credit - debit must equal printed balance. */
    private static function checkRunningBalance(array $t, array $fields): array
    {
        $dr = $cr = $bal = null;
        foreach ($t['columns'] as $i => $c) {
            $lc = strtolower($c);
            if ($bal === null && str_contains($lc, 'balance')) {
                $bal = $i;
            } elseif ($dr === null && preg_match('/debit|withdraw|\bdr\b|paid out/', $lc)) {
                $dr = $i;
            } elseif ($cr === null && preg_match('/credit|deposit|\bcr\b|paid in/', $lc)) {
                $cr = $i;
            }
        }
        if ($dr === null || $cr === null || $bal === null) {
            return [];
        }

        $flags = [];
        $prev = null;
        $checked = 0;
        $mismatch = [];
        $sumDr = $sumCr = 0.0;
        foreach ($t['rows'] as $n => $r) {
            $b = self::parseNumber($r[$bal] ?? null);
            $d = self::parseNumber($r[$dr] ?? null) ?? 0.0;
            $c = self::parseNumber($r[$cr] ?? null) ?? 0.0;
            $sumDr += abs($d);
            $sumCr += abs($c);
            if ($b === null) {
                continue;
            }
            if ($prev !== null && ($d != 0.0 || $c != 0.0)) {
                $checked++;
                $expected = $prev + abs($c) - abs($d);
                if (abs($expected - $b) > self::TOL) {
                    $mismatch[] = $n + 1;
                }
            }
            $prev = $b;
        }

        if ($checked === 0) {
            return [];
        }
        if ($mismatch) {
            $flags[] = self::flag('critical', $t['title'],
                sprintf('Running balance does not reconcile on %d of %d rows (rows %s%s). Either a row was misread or the statement is inconsistent.',
                    count($mismatch), $checked, implode(', ', array_slice($mismatch, 0, 5)), count($mismatch) > 5 ? ', ...' : ''));
        } else {
            $flags[] = self::flag('info', $t['title'], "Running balance verified: all $checked transaction rows reconcile.");
        }

        // Compare against printed totals, if the document prints them.
        foreach ($fields as $f) {
            if ($f['numeric'] === null) {
                continue;
            }
            $lc = strtolower($f['label']);
            $isDr = preg_match('/total.*(debit|withdraw)|(debit|withdraw).*total/', $lc);
            $isCr = preg_match('/total.*(credit|deposit)|(credit|deposit).*total/', $lc);
            if ($isDr || $isCr) {
                $tableSum = $isDr ? $sumDr : $sumCr;
                if (abs(abs($f['numeric']) - $tableSum) > self::TOL) {
                    $flags[] = self::flag('critical', $f['label'], sprintf('Printed %s (%s) does not match the sum of the table column (%s).',
                        $f['label'], $f['value'], number_format($tableSum, 2)));
                } else {
                    $flags[] = self::flag('info', $f['label'], 'Printed total matches the sum of the table column.');
                }
            }
        }
        return $flags;
    }

    /** Taxable value + printed tax amounts must equal the printed grand total. */
    private static function checkInvoiceTotal(array $fields): array
    {
        $taxable = $grand = null;
        $taxes = [];
        foreach ($fields as $f) {
            if ($f['numeric'] === null || $f['value_type'] === 'percentage') {
                continue;
            }
            $lc = strtolower($f['label']);
            // skip tax *rates* ("IGST Rate", "CGST %", value "18%") but keep amounts like "IGST @ 18%"
            if (str_contains((string) $f['value'], '%') || preg_match('/\brate\b|percent|^\s*[a-z]+\s*%\s*$/', $lc)) {
                continue;
            }
            if ($taxable === null && preg_match('/taxable/', $lc) && !preg_match('/non[- ]?taxable/', $lc)) {
                $taxable = $f;
            } elseif ($grand === null && preg_match('/grand total|invoice total|total invoice|total amount|amount payable|net payable/', $lc)) {
                $grand = $f;
            } elseif (preg_match('/\b(igst|cgst|sgst|utgst|cess)\b/', $lc) && !preg_match('/taxable/', $lc)) {
                $taxes[] = $f;
            }
        }
        if ($taxable === null || $grand === null || !$taxes) {
            return [];
        }
        $sum = $taxable['numeric'] + array_sum(array_map(fn($t) => $t['numeric'], $taxes));
        $diff = $grand['numeric'] - $sum;
        if (abs($diff) <= self::TOL) {
            return [self::flag('info', $grand['label'], 'Grand total matches taxable value plus printed tax amounts.')];
        }
        $sev = abs($diff) <= 1.0 ? 'warning' : 'critical';
        $note = abs($diff) <= 1.0 ? ' (likely round-off - check for a printed round-off line)' : '';
        return [self::flag($sev, $grand['label'], sprintf('Grand total (%s) differs from taxable value plus taxes by %s%s.', $grand['value'], number_format($diff, 2), $note))];
    }

    // ------------------------------------------------------------ helpers

    /** Parses a printed number: "₹ 1,23,456.78", "(1,200.00)", "1,200.00 Dr", "18%". */
    public static function parseNumber(?string $s): ?float
    {
        if ($s === null) {
            return null;
        }
        $t = trim($s);
        if ($t === '' || $t === '-' || $t === '--') {
            return null;
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $t, $m)) {
            $neg = true;
            $t = $m[1];
        }
        $t = preg_replace('/(₹|rs\.?|inr|usd|\$|%|\b(dr|cr)\b\.?)/iu', '', $t);
        $t = str_replace([',', ' ', "\u{00A0}"], '', (string) $t);
        if (str_ends_with($t, '-')) {
            $neg = true;
            $t = rtrim($t, '-');
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $t)) {
            return null;
        }
        $v = (float) $t;
        return $neg ? -abs($v) : $v;
    }

    public static function parseDate(string $s): ?string
    {
        $s = trim($s);
        foreach (['d-m-Y', 'd/m/Y', 'd.m.Y', 'Y-m-d', 'd-M-Y', 'd M Y', 'd-M-y', 'd/m/y', 'M d, Y', 'd F Y', 'F d, Y', 'M Y', 'F Y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $s);
            if ($d !== false && $d->format($fmt) === $s) {
                return $d->format('Y-m-d');
            }
        }
        // Ranges like "01-Sep-2026 to 30-Sep-2026" are fine as text.
        return preg_match('/\d.*\b(to|-)\b.*\d/i', $s) ? $s : null;
    }

    /** Columns where most non-empty cells parse as numbers (used for Excel + charts). */
    private static function numericColumns(array $cols, array $rows): array
    {
        $out = [];
        foreach ($cols as $i => $c) {
            $n = $ok = 0;
            foreach ($rows as $r) {
                if (($r[$i] ?? null) === null) {
                    continue;
                }
                $n++;
                if (self::parseNumber($r[$i]) !== null) {
                    $ok++;
                }
            }
            $isId = (bool) preg_match('/\b(no|number|ref|id|code|hsn|sac|chq|cheque|#|sr|s\.?no)\b/i', $c);
            if ($n > 0 && $ok / $n >= 0.8 && !$isId) {
                $out[] = $i;
            }
        }
        return $out;
    }

    private static function flag(string $sev, ?string $field, string $msg): array
    {
        return ['severity' => $sev, 'field' => $field, 'message' => $msg, 'source' => 'validator'];
    }

    private static function rank(string $s): int
    {
        return ['info' => 0, 'warning' => 1, 'critical' => 2][$s] ?? 0;
    }

    private static function slug(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
    }

    /**
     * Indian identifiers have fixed formats, so a misread character (common with scans)
     * usually breaks the pattern. Returns a message when a value doesn't fit, else null.
     */
    public static function checkIdentifierFormat(string $label, string $value): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        if (strlen($v) > 20) {
            return null; // a sentence that mentions the identifier, not the identifier itself
        }
        $rules = [
            ['/\bGSTIN\b|\bGST\s*(No|Number|Reg)/i', '/^\d{2}[A-Z]{5}\d{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', 'GSTIN', '15 characters: 2-digit state code, PAN, entity code, Z, check digit'],
            ['/\bIFSC\b/i', '/^[A-Z]{4}0[A-Z0-9]{6}$/', 'IFSC', '11 characters: 4 letters, 0, 6 letters/digits'],
            ['/\bPAN\b/i', '/^[A-Z]{5}\d{4}[A-Z]$/', 'PAN', '10 characters: 5 letters, 4 digits, 1 letter'],
        ];
        foreach ($rules as [$labelRe, $formatRe, $name, $shape]) {
            if (preg_match($labelRe, $label)) {
                return preg_match($formatRe, $v) ? null
                    : "\"$value\" is not a valid $name ($shape; this has " . strlen($v) . '). Likely misread - check the original.';
            }
        }
        return null;
    }
}
