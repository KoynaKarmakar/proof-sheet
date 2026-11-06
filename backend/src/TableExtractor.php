<?php
declare(strict_types=1);

namespace App;

/**
 * Pulls tables (bank statement transactions, invoice line items, ...) straight out of a
 * digital PDF's text layer, using word positions from `pdftotext -bbox-layout`. No AI, so it
 * is exact (values are the printed characters) and fast (a 14-page statement takes ~0.1 s).
 *
 * How it works, per page:
 *  1. Rebuild text lines from word positions.
 *  2. Find the header line: several words like Date / Particulars / Debit / Credit / Balance /
 *     Amount. Header labels can wrap onto the next lines ("Transaction" / "Date" / "(dd/mm/yyyy)").
 *  3. Find the columns from the data itself: vertical strips of white space ("gutters") below
 *     the header split the page into columns, which are then matched to the header labels in
 *     left-to-right order.
 *  4. Group lines into rows. A row is anchored by a date (or serial number); wrapped description
 *     lines above or below the anchor are folded into the same row, using the blank space
 *     between rows when the statement has it.
 *  5. Pages without a header (continuation pages) reuse the previous page's columns.
 *  6. Tables with the same columns on consecutive pages are joined into one.
 *
 * It also returns each page's text *without* the table rows, so the LLM only has to read the
 * remaining text (account details, summaries) instead of hundreds of transaction lines.
 */
final class TableExtractor
{
    private const HEADER_WORDS = '/^(s\.?\s?no\.?|sr\.?|sl\.?|no\.?|#|date|txn|tran|transaction|value|posting|particulars?|narration|description|details|remarks|debit|credit|withdrawals?|deposits?|dr|cr|balance|amount|amt|chq|cheque|ref|reference|type|branch|qty|quantity|rate|hsn|sac|taxable|igst|cgst|sgst|tax|total|item|mode|code|instrument|utr)/i';
    private const DATE_RE = '/^\d{1,2}[\/\-.](\d{1,2}|[A-Za-z]{3})[\/\-.]\d{2,4}$/';
    private const AMOUNT_RE = '/^[-(]?[-,]*\d[\d,]*\.\d{1,2}\)?(\s?(cr|dr))?$/i';

    /**
     * @return array{tables: list<array>, residual: array<int,string>, rows: int}
     *   residual: page number => that page's text with table header and rows removed
     */
    public static function fromBboxXml(string $xml): array
    {
        $pages = self::parsePages($xml);
        $tables = [];
        $residual = [];
        $layout = null; // columns carried over to continuation pages

        foreach ($pages as $pageNo => $words) {
            $lines = self::buildLines($words);
            $used = [];
            [$table, $layout, $usedLines] = self::extractFromPage($lines, $layout, $pageNo);
            if ($table !== null) {
                $tables[] = $table;
                $used = $usedLines;
            }
            $residual[$pageNo] = self::residualText($lines, $used);
        }

        $tables = self::joinContinuations($tables);
        $rows = array_sum(array_map(fn ($t) => count($t['rows']), $tables));
        return ['tables' => $tables, 'residual' => $residual, 'rows' => $rows];
    }

    // ---------------------------------------------------------------- parsing

    /** @return array<int, list<array{x0:float,y0:float,x1:float,y1:float,t:string}>> */
    private static function parsePages(string $xml): array
    {
        $pages = [];
        // The XHTML is regular; a streaming regex is much faster than a DOM for big files.
        $parts = preg_split('/<page\b/', $xml);
        array_shift($parts);
        foreach ($parts as $i => $chunk) {
            preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/word>/s', $chunk, $m, PREG_SET_ORDER);
            $words = [];
            foreach ($m as $w) {
                $t = html_entity_decode($w[5], ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (trim($t) === '') {
                    continue;
                }
                $words[] = ['x0' => (float) $w[1], 'y0' => (float) $w[2], 'x1' => (float) $w[3], 'y1' => (float) $w[4], 't' => $t];
            }
            $pages[$i + 1] = $words;
        }
        return $pages;
    }

    /** Groups words into visual lines (same baseline within a small tolerance), top to bottom. */
    private static function buildLines(array $words): array
    {
        usort($words, fn ($a, $b) => [$a['y0'], $a['x0']] <=> [$b['y0'], $b['x0']]);
        $lines = [];
        foreach ($words as $w) {
            $h = max(1.0, $w['y1'] - $w['y0']);
            $last = array_key_last($lines);
            if ($last !== null && abs($lines[$last]['y0'] - $w['y0']) < $h * 0.45) {
                $lines[$last]['words'][] = $w;
                $lines[$last]['y1'] = max($lines[$last]['y1'], $w['y1']);
            } else {
                $lines[] = ['y0' => $w['y0'], 'y1' => $w['y1'], 'h' => $h, 'words' => [$w]];
            }
        }
        foreach ($lines as &$l) {
            usort($l['words'], fn ($a, $b) => $a['x0'] <=> $b['x0']);
        }
        return $lines;
    }

    // ------------------------------------------------------------ one page

    /** @return array{0: ?array, 1: ?array, 2: array<int,bool>} [table, layout, used line indexes] */
    private static function extractFromPage(array $lines, ?array $prevLayout, int $pageNo): array
    {
        $hdr = self::findHeader($lines);
        $used = [];
        if ($hdr !== null) {
            [$hStart, $hEnd] = $hdr;
            for ($i = $hStart; $i <= $hEnd; $i++) {
                $used[$i] = true;
            }
            $headerCols = self::headerColumns(array_slice($lines, $hStart, $hEnd - $hStart + 1));
            $start = $hEnd + 1;
        } elseif ($prevLayout !== null) {
            $headerCols = $prevLayout['header'];
            $start = self::firstAnchorGroupStart($lines, $prevLayout);
            if ($start === null) {
                return [null, $prevLayout, []];
            }
        } else {
            return [null, null, []];
        }
        if (count($headerCols) < 3) {
            return [null, $prevLayout, []];
        }

        $end = self::regionEnd($lines, $start, $headerCols);
        $region = array_slice($lines, $start, $end - $start, true);
        if ($region === []) {
            return [null, ['header' => $headerCols, 'cols' => $prevLayout['cols'] ?? null], $used];
        }

        $cols = self::columnsFromData($region, $headerCols) ?? ($prevLayout['cols'] ?? null);
        if ($cols === null) {
            return [null, $prevLayout, []];
        }
        $layout = ['header' => $headerCols, 'cols' => $cols];

        $records = self::groupRecords($region, $cols, $headerCols);
        $rows = [];
        foreach ($records as $rec) {
            $cells = self::recordCells($rec['lines'], $cols, count($headerCols));
            if (!self::looksLikeDataRow($cells, $headerCols, self::anchorCols($headerCols))) {
                continue;
            }
            $rows[] = $cells;
            foreach ($rec['idx'] as $li) {
                $used[$li] = true;
            }
        }
        if (count($rows) === 0) {
            return [null, $layout, $hdr !== null ? $used : []];
        }

        return [[
            'title' => 'Transactions',
            'page' => $pageNo,
            'columns' => array_map(fn ($c) => $c['label'], $headerCols),
            'rows' => $rows,
            'source' => 'parser',
        ], $layout, $used];
    }

    /** Finds the table header: a line with 3+ header-like words, plus wrapped label lines below. */
    private static function findHeader(array $lines): ?array
    {
        foreach ($lines as $i => $l) {
            $hits = 0;
            $strong = false;
            foreach ($l['words'] as $w) {
                if (preg_match(self::HEADER_WORDS, $w['t'])) {
                    $hits++;
                    if (preg_match('/balance|amount|debit|credit|withdraw|deposit|particular|narration|description|taxable|qty/i', $w['t'])) {
                        $strong = true;
                    }
                }
            }
            if ($hits < 3 || !$strong || self::lineHasData($l)) {
                continue;
            }
            // Wrapped header labels: following close lines with no dates/amounts.
            $end = $i;
            for ($j = $i + 1; $j < count($lines) && $j <= $i + 3; $j++) {
                $gap = $lines[$j]['y0'] - $lines[$j - 1]['y1'];
                if ($gap > $lines[$j]['h'] * 1.2 || self::lineHasData($lines[$j])) {
                    break;
                }
                $end = $j;
            }
            return [$i, $end];
        }
        return null;
    }

    private static function lineHasData(array $l): bool
    {
        foreach ($l['words'] as $w) {
            if (preg_match(self::DATE_RE, $w['t']) || preg_match(self::AMOUNT_RE, $w['t'])) {
                return true;
            }
        }
        return false;
    }

    /** Header words -> column labels with x extents. Stacked or adjacent words join one label. */
    private static function headerColumns(array $hLines): array
    {
        $words = [];
        foreach ($hLines as $li => $l) {
            foreach ($l['words'] as $w) {
                $words[] = $w + ['line' => $li, 'h' => $l['h']];
            }
        }
        $n = count($words);
        $parent = range(0, $n - 1);
        $find = function ($i) use (&$parent, &$find) { return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i])); };
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $wa = $words[$a];
                $wb = $words[$b];
                $sameLine = $wa['line'] === $wb['line'];
                $gap = max($wa['x0'], $wb['x0']) - min($wa['x1'], $wb['x1']);
                if (($sameLine && $gap < $wa['h'] * 0.6) || (!$sameLine && $gap < 0)) {
                    $parent[$find($a)] = $find($b);
                }
            }
        }
        $groups = [];
        foreach ($words as $i => $w) {
            $groups[$find($i)][] = $w;
        }
        $cols = [];
        foreach ($groups as $g) {
            usort($g, fn ($a, $b) => [$a['line'], $a['x0']] <=> [$b['line'], $b['x0']]);
            $cols[] = [
                'label' => trim(implode(' ', array_column($g, 't'))),
                'x0' => min(array_column($g, 'x0')),
                'x1' => max(array_column($g, 'x1')),
            ];
        }
        usort($cols, fn ($a, $b) => $a['x0'] <=> $b['x0']);
        return $cols;
    }

    /**
     * Last line index (exclusive) of the table. Rows repeat at a steady rhythm (the distance
     * between dated lines); a gap clearly bigger than that ends the table (summary, legend, footer).
     */
    private static function regionEnd(array $lines, int $start, array $headerCols): int
    {
        $n = count($lines);
        // Row-start lines: a date anywhere, or a serial number in the first column.
        $firstColEnd = isset($headerCols[1]) ? $headerCols[1]['x0'] : INF;
        $isRowStart = function (array $l) use ($firstColEnd) {
            foreach ($l['words'] as $w) {
                if (preg_match(self::DATE_RE, $w['t'])) {
                    return true;
                }
            }
            $w0 = $l['words'][0];
            return $w0['x1'] <= $firstColEnd && preg_match('/^\d{1,5}\.?$/', $w0['t']) === 1;
        };
        $anchorYs = [];
        for ($i = $start; $i < $n; $i++) {
            if ($isRowStart($lines[$i])) {
                $anchorYs[$i] = $lines[$i]['y0'];
            }
        }
        if ($anchorYs === []) {
            return $start;
        }
        $ys = array_values($anchorYs);
        $pitches = [];
        for ($k = 1; $k < count($ys); $k++) {
            $pitches[] = $ys[$k] - $ys[$k - 1];
        }
        sort($pitches);
        $rowPitch = $pitches ? $pitches[intdiv(count($pitches), 2)] : $lines[$start]['h'] * 3;
        $limit = max($rowPitch * 1.6, $lines[$start]['h'] * 2.5);
        $lastAnchorY = null;
        for ($i = $start; $i < $n; $i++) {
            if (isset($anchorYs[$i])) {
                $lastAnchorY = $anchorYs[$i];
                continue;
            }
            // too far below the last row start (rows have stopped), or a big blank gap
            if (($lastAnchorY !== null && $lines[$i]['y0'] - $lastAnchorY > $limit)
                || ($i > $start && $lines[$i]['y0'] - $lines[$i - 1]['y0'] > $limit)) {
                return $i;
            }
        }
        return $n;
    }

    /**
     * Columns from the data: x ranges covered by words, separated by white-space gutters, then
     * matched in order to the header labels (so right-aligned numbers that sit outside their
     * header label still land in the right column).
     */
    private static function columnsFromData(array $region, array $headerCols): ?array
    {
        // Coverage histogram (1pt bins): how many lines have ink at each x. A gutter is where
        // (almost) no line has ink, so one stray wide line can't bridge two columns.
        $cover = [];
        $hs = [];
        foreach ($region as $l) {
            $hs[] = $l['h'];
            $seen = [];
            foreach ($l['words'] as $w) {
                for ($x = (int) floor($w['x0']); $x <= (int) ceil($w['x1']); $x++) {
                    $seen[$x] = true;
                }
            }
            foreach ($seen as $x => $_) {
                $cover[$x] = ($cover[$x] ?? 0) + 1;
            }
        }
        if ($cover === []) {
            return null;
        }
        sort($hs);
        $h = $hs[intdiv(count($hs), 2)];
        $minCover = max(1, (int) floor(count($region) * 0.04));
        ksort($cover);
        $segs = [];
        foreach ($cover as $x => $c) {
            if ($c < $minCover) {
                continue;
            }
            $last = array_key_last($segs);
            if ($last !== null && $x <= $segs[$last][1] + 1) {
                $segs[$last][1] = $x;
            } else {
                $segs[] = [$x, $x];
            }
        }
        // Gaps narrower than a couple of characters are word spaces, not gutters.
        $merged = [];
        foreach ($segs as $sg) {
            $last = array_key_last($merged);
            if ($last !== null && $sg[0] - $merged[$last][1] < $h * 0.7) {
                $merged[$last][1] = $sg[1];
            } else {
                $merged[] = $sg;
            }
        }
        if (count($merged) < 2) {
            return null;
        }
        $map = self::alignInOrder($merged, $headerCols);

        // Each header column owns the x range of its data segment(s), or its label if empty.
        $cols = [];
        foreach ($headerCols as $j => $hc) {
            $cols[$j] = ['x0' => $hc['x0'], 'x1' => $hc['x1'], 'data' => false];
        }
        foreach ($map as $si => $j) {
            if (!$cols[$j]['data']) {
                $cols[$j] = ['x0' => $merged[$si][0], 'x1' => $merged[$si][1], 'data' => true];
            } else {
                $cols[$j]['x0'] = min($cols[$j]['x0'], $merged[$si][0]);
                $cols[$j]['x1'] = max($cols[$j]['x1'], $merged[$si][1]);
            }
        }
        // Cut points halfway between neighbouring columns.
        $cuts = [];
        for ($j = 0; $j < count($cols) - 1; $j++) {
            $cuts[$j] = ($cols[$j]['x1'] + $cols[$j + 1]['x0']) / 2;
        }
        return ['cuts' => $cuts, 'n' => count($cols)];
    }

    /**
     * Order-preserving matching of data segments to header columns that minimises
     * the horizontal distance between them (dynamic programming).
     * @return array<int,int> segment index => header column index
     */
    private static function alignInOrder(array $segs, array $hdr): array
    {
        $S = count($segs);
        $H = count($hdr);
        $dist = function ($s, $h) use ($segs, $hdr) {
            $a = $segs[$s];
            $b = $hdr[$h];
            return max(0.0, $a[0] - $b['x1'], $b['x0'] - $a[1]);
        };
        $INF = INF;
        $f = array_fill(0, $S + 1, array_fill(0, $H + 1, $INF));
        $back = [];
        for ($j = 0; $j <= $H; $j++) {
            $f[0][$j] = 0.0;
        }
        for ($i = 1; $i <= $S; $i++) {
            for ($j = 1; $j <= $H; $j++) {
                // seg i-1 -> header j-1 (new column, or the same column as seg i-2),
                // or header j-1 left without data
                $take = $f[$i - 1][$j - 1] + $dist($i - 1, $j - 1);
                $same = $i > 1 && ($back[$i - 1][$j] ?? null) !== null && ($back[$i - 1][$j] !== 'skip')
                    ? $f[$i - 1][$j] + $dist($i - 1, $j - 1) + 0.5 : INF;
                $skip = $f[$i][$j - 1];
                $best = min($take, $same, $skip);
                $f[$i][$j] = $best;
                $back[$i][$j] = $best === $take ? 'take' : ($best === $same ? 'same' : 'skip');
            }
        }
        $map = [];
        $i = $S;
        $j = $H;
        while ($i > 0 && $j > 0) {
            $b = $back[$i][$j] ?? 'skip';
            if ($b === 'take') {
                $map[$i - 1] = $j - 1;
                $i--;
                $j--;
            } elseif ($b === 'same') {
                $map[$i - 1] = $j - 1;
                $i--;
            } else {
                $j--;
            }
        }
        return $map;
    }

    private static function colOf(array $w, array $cols): int
    {
        $cx = ($w['x0'] + $w['x1']) / 2;
        foreach ($cols['cuts'] as $j => $cut) {
            if ($cx < $cut) {
                return $j;
            }
        }
        return $cols['n'] - 1;
    }

    /** Index of the "anchor" column: first date column, else a serial-number column. */
    private static function anchorCols(array $headerCols): array
    {
        $date = $serial = [];
        foreach ($headerCols as $j => $h) {
            if (preg_match('/date/i', $h['label'])) {
                $date[] = $j;
            } elseif (preg_match('/^(s\.?\s?no|sr|sl|no\b|#)/i', $h['label'])) {
                $serial[] = $j;
            }
        }
        return $date ?: $serial;
    }

    private static function isAnchorLine(array $l, array $cols, array $anchors): bool
    {
        foreach ($l['words'] as $w) {
            $j = self::colOf($w, $cols);
            if (in_array($j, $anchors, true) && (preg_match(self::DATE_RE, $w['t']) || preg_match('/^\d{1,5}\.?$/', $w['t']))) {
                return true;
            }
        }
        return false;
    }

    /** Where rows start on a continuation page (first block containing an anchor line). */
    private static function firstAnchorGroupStart(array $lines, array $layout): ?int
    {
        if (empty($layout['cols'])) {
            return null;
        }
        $anchors = self::anchorCols($layout['header']);
        foreach ($lines as $i => $l) {
            if (self::isAnchorLine($l, $layout['cols'], $anchors)) {
                // include wrapped lines just above the anchor (vertically centred rows)
                $s = $i;
                while ($s > 0 && $lines[$s]['y0'] - $lines[$s - 1]['y1'] < $lines[$s]['h'] * 0.8 && !self::looksLikeHeaderOrTitle($lines[$s - 1])) {
                    $s--;
                }
                return $s;
            }
        }
        return null;
    }

    private static function looksLikeHeaderOrTitle(array $l): bool
    {
        $hits = 0;
        foreach ($l['words'] as $w) {
            $hits += preg_match(self::HEADER_WORDS, $w['t']) ? 1 : 0;
        }
        return $hits >= 3;
    }

    /**
     * Lines -> rows. Blank space between rows separates them when present; within a block,
     * each anchor line (date / serial) starts a row, and lines with no anchor belong to the
     * nearest anchor in their block.
     * @return list<array{lines: list<array>, idx: list<int>}>
     */
    private static function groupRecords(array $region, array $cols, array $headerCols): array
    {
        $anchors = self::anchorCols($headerCols);
        $idx = array_keys($region);
        // pitch inside a row vs between rows
        $gaps = [];
        for ($k = 1; $k < count($idx); $k++) {
            $gaps[] = $region[$idx[$k]]['y0'] - $region[$idx[$k - 1]]['y1'];
        }
        $minGap = $gaps ? max(0.0, min($gaps)) : 0.0;
        $h = $region[$idx[0]]['h'];
        $blockBreak = $minGap + $h * 0.6;

        // 1) blocks separated by blank space
        $blocks = [[]];
        foreach ($idx as $k => $li) {
            if ($k > 0 && $gaps[$k - 1] > $blockBreak) {
                $blocks[] = [];
            }
            $blocks[array_key_last($blocks)][] = $li;
        }
        $hasBlankRows = count($blocks) > 1;

        // 2) split blocks at anchor lines
        $records = [];
        foreach ($blocks as $block) {
            $anchorsIn = array_values(array_filter($block, fn ($li) => self::isAnchorLine($region[$li], $cols, $anchors)));
            if ($anchors === [] || count($anchorsIn) <= 1) {
                $records[] = $block;
                continue;
            }
            if ($hasBlankRows) {
                // several rows packed in one block: cut halfway between anchors
                $cur = [];
                $ai = 0;
                foreach ($block as $li) {
                    if ($ai + 1 < count($anchorsIn) && $li >= $anchorsIn[$ai + 1]) {
                        $records[] = $cur;
                        $cur = [];
                        $ai++;
                    }
                    $cur[] = $li;
                }
                $records[] = $cur;
            } else {
                // dense table: a row starts at its anchor; following lines continue it, unless the
                // line is a total, or puts a second number in a column the row already has one in
                $cur = [];
                $filled = [];
                foreach ($block as $li) {
                    $isAnchor = in_array($li, $anchorsIn, true);
                    $breaks = $cur !== [] && (
                        ($isAnchor && self::containsAnchor($cur, $anchorsIn))
                        || (!$isAnchor && self::isSeparateLine($region[$li], $cols, $filled))
                    );
                    if ($breaks) {
                        $records[] = $cur;
                        $cur = [];
                        $filled = [];
                    }
                    $cur[] = $li;
                    foreach ($region[$li]['words'] as $w) {
                        if (preg_match(self::AMOUNT_RE, $w['t'])) {
                            $filled[self::colOf($w, $cols)] = true;
                        }
                    }
                }
                $records[] = $cur;
            }
        }

        return array_map(fn ($rec) => ['lines' => array_map(fn ($li) => $region[$li], $rec), 'idx' => $rec], array_filter($records));
    }

    /** A non-anchor line that is a totals line, or would put a 2nd amount in a filled column. */
    private static function isSeparateLine(array $l, array $cols, array $filled): bool
    {
        $text = implode(' ', array_column($l['words'], 't'));
        if (preg_match('/\b(total|sub-?total|grand|closing|carried|brought|balance\s+c\/?f|balance\s+b\/?f)\b/i', $text)) {
            return true;
        }
        foreach ($l['words'] as $w) {
            if (preg_match(self::AMOUNT_RE, $w['t']) && isset($filled[self::colOf($w, $cols)])) {
                return true;
            }
        }
        return false;
    }

    private static function containsAnchor(array $rec, array $anchors): bool
    {
        return array_intersect($rec, $anchors) !== [];
    }

    /** @return list<?string> one cell per header column */
    private static function recordCells(array $lines, array $cols, int $n): array
    {
        $cells = array_fill(0, $n, []);
        foreach ($lines as $l) {
            foreach ($l['words'] as $w) {
                $cells[self::colOf($w, $cols)][] = $w['t'];
            }
        }
        return array_map(fn ($ws) => $ws === [] ? null : implode(' ', $ws), $cells);
    }

    /**
     * A real row has a date or serial number in its anchor column and a date or amount
     * somewhere. Totals, closing-balance lines, legends and footers don't, so they stay in the
     * text the LLM reads.
     */
    private static function looksLikeDataRow(array $cells, array $headerCols, array $anchors): bool
    {
        $filled = count(array_filter($cells, fn ($c) => $c !== null));
        if ($filled < 2) {
            return false;
        }
        if ($anchors !== []) {
            $ok = false;
            foreach ($anchors as $j) {
                $first = explode(' ', (string) ($cells[$j] ?? ''))[0];
                if (preg_match(self::DATE_RE, $first) || preg_match('/^\d{1,5}\.?$/', $first)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        foreach ($cells as $c) {
            if ($c === null) {
                continue;
            }
            foreach (explode(' ', $c) as $tok) {
                if (preg_match(self::DATE_RE, $tok) || preg_match(self::AMOUNT_RE, $tok)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function residualText(array $lines, array $used): string
    {
        $out = [];
        foreach ($lines as $i => $l) {
            if (isset($used[$i])) {
                continue;
            }
            $out[] = implode(' ', array_column($l['words'], 't'));
        }
        return trim(implode("\n", $out));
    }

    /** Joins a table with the previous one when the columns match (continued on the next page). */
    private static function joinContinuations(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['columns'] === $t['columns']) {
                array_push($out[$last]['rows'], ...$t['rows']);
            } else {
                $out[] = $t;
            }
        }
        // Name tables by what they hold.
        foreach ($out as $i => &$t) {
            $lc = strtolower(implode(' ', $t['columns']));
            $t['title'] = preg_match('/balance/', $lc) ? 'Transactions'
                : (preg_match('/hsn|sac|qty|quantity|taxable/', $lc) ? 'Line items' : 'Table ' . ($i + 1));
        }
        return $out;
    }
}
