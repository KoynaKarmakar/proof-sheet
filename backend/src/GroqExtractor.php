<?php
declare(strict_types=1);

namespace App;

/**
 * Extraction through Groq (free tier friendly), returning the same shape as the Claude path.
 *
 * - Digital PDFs: the printed text is pulled out with pdftotext and read by a text model
 *   (default openai/gpt-oss-120b). Copying from the text layer is exact, so it is the more
 *   accurate route for bank statements and e-invoices.
 * - Scanned PDF pages and image uploads: sent as images to a vision model
 *   (default qwen/qwen3.8-27b).
 * - Groq's free tier allows only ~8K tokens per minute, so documents are read a few pages at
 *   a time and the results are merged. Rate-limit (429) replies are waited out and retried.
 * - Output is forced into the extraction JSON schema with strict Structured Outputs.
 */
final class GroqExtractor
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';
    /** A page with less printed text than this is treated as a scan and sent as an image. */
    private const MIN_TEXT_CHARS = 40;

    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private bool $truncated = false;
    /** @var list<string> */
    private array $models = [];
    /** @var list<array> */
    private array $extraFlags = [];

    /** @return array{extraction: array, meta: array} */
    /** @param ?callable(int $done, int $total): void $onProgress called as each batch of pages finishes */
    public function extract(string $bytes, string $mime, string $filename, ?callable $onProgress = null): array
    {
        $chunks = $mime === 'application/pdf'
            ? $this->pdfChunks($bytes, $pageCount)
            : [['kind' => 'images', 'pages' => [1], 'images' => [[$mime, $bytes]]]];
        $pageCount ??= 1;

        $parts = [];
        $total = count($chunks);
        if ($onProgress) { $onProgress(0, $total); }
        foreach ($chunks as $i => $chunk) {
            $part = $this->readChunk($chunk, $pageCount, $filename);
            if ($part !== null) {
                $parts[] = $part;
            }
            if ($onProgress) { $onProgress($i + 1, $total); }
        }
        if ($parts === []) {
            throw new HttpException(502, 'The model did not return a structured extraction for any page');
        }

        $merged = self::merge($parts);
        $merged['page_count'] = $pageCount;
        array_push($merged['flags'], ...$this->extraFlags);

        return [
            'extraction' => $merged,
            'meta' => [
                'model' => implode(' + ', array_values(array_unique($this->models))),
                'stop_reason' => $this->truncated ? 'max_tokens' : 'end_turn',
                'input_tokens' => $this->inputTokens,
                'output_tokens' => $this->outputTokens,
            ],
        ];
    }

    /**
     * Splits a PDF into requests: runs of text pages up to a token budget, and scanned pages
     * as images (a few per request).
     * @return list<array>
     */
    private function pdfChunks(string $bytes, ?int &$pageCount): array
    {
        $pdf = new PdfTools($bytes);
        $pageCount = $pdf->pageCount();
        $maxPages = (int) Env::get('GROQ_MAX_PAGES', '30');
        if ($pageCount > $maxPages) {
            throw new HttpException(422, "This PDF has $pageCount pages; the limit is $maxPages (GROQ_MAX_PAGES). Split it first.");
        }
        $textBudget = (int) Env::get('GROQ_TEXT_CHUNK_TOKENS', '1800');
        $imagesPerRequest = max(1, min(3, (int) Env::get('GROQ_IMAGES_PER_REQUEST', '1')));
        $dpi = (int) Env::get('GROQ_IMAGE_DPI', '150');

        $chunks = [];
        $cur = null;
        for ($p = 1; $p <= $pageCount; $p++) {
            $text = $pdf->pageText($p);
            $isText = mb_strlen(preg_replace('/\s+/', '', $text) ?? '') >= self::MIN_TEXT_CHARS;
            $cost = self::estimateTokens($text);

            $fits = $cur !== null && (
                ($isText && $cur['kind'] === 'text' && $cur['tokens'] + $cost <= $textBudget)
                || (!$isText && $cur['kind'] === 'images' && count($cur['images']) < $imagesPerRequest)
            );
            if (!$fits) {
                if ($cur !== null) {
                    $chunks[] = $cur;
                }
                $cur = ['kind' => $isText ? 'text' : 'images', 'pages' => [], 'text' => '', 'tokens' => 0, 'images' => []];
            }
            $cur['pages'][] = $p;
            if ($isText) {
                $cur['text'] .= "\n\n===== Page $p =====\n" . $text;
                $cur['tokens'] += $cost;
            } else {
                $cur['images'][] = ['image/jpeg', $pdf->pageJpeg($p, $dpi)];
            }
        }
        if ($cur !== null) {
            $chunks[] = $cur;
        }
        return $chunks;
    }

    private function readChunk(array $chunk, int $pageCount, string $filename): ?array
    {
        $pages = $chunk['pages'];
        $range = count($pages) === 1 ? "page {$pages[0]}" : 'pages ' . $pages[0] . '-' . end($pages);
        $partial = count($pages) < $pageCount;

        $intro = "File name: $filename\n";
        $intro .= $partial
            ? "This request covers $range of a $pageCount-page document. Extract only what is printed on these pages. "
              . "Give every field and table row its real page number. Do NOT add null placeholder fields for values that "
              . "may be printed on other pages. If a table continues from or onto another page, keep the same table title "
              . "and the printed column headers, and do not repeat the header row as a data row.\n"
            : "This is the whole document ($pageCount page" . ($pageCount > 1 ? 's' : '') . ").\n";
        $intro .= 'Extract it following the rules and return the JSON object.';

        if ($chunk['kind'] === 'text') {
            $model = Env::get('GROQ_TEXT_MODEL', 'openai/gpt-oss-120b');
            $content = $intro . "\n\nThe printed text below was taken from the PDF's text layer, page by page, "
                . "with the column layout preserved:\n" . $chunk['text'];
            $reasoning = Env::get('GROQ_TEXT_REASONING_EFFORT', 'low');
        } else {
            $model = Env::get('GROQ_VISION_MODEL', 'qwen/qwen3.8-27b');
            $content = [['type' => 'text', 'text' => $intro]];
            foreach ($chunk['images'] as [$imgMime, $imgBytes]) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:$imgMime;base64," . base64_encode($imgBytes)]];
            }
            $reasoning = Env::get('GROQ_VISION_REASONING_EFFORT', 'none');
        }

        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_completion_tokens' => (int) Env::get('GROQ_MAX_TOKENS', '4500'),
            'messages' => [
                ['role' => 'system', 'content' => Extractor::SYSTEM],
                ['role' => 'user', 'content' => $content],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'record_extraction',
                    'strict' => true,
                    'schema' => self::strictSchema(Extractor::toolSchema()['input_schema']),
                ],
            ],
        ];
        if ($reasoning !== null && $reasoning !== '' && $reasoning !== 'off') {
            $payload['reasoning_effort'] = $reasoning;
        }

        $res = $this->call($payload);
        $this->models[] = $res['model'] ?? $model;
        $this->inputTokens += (int) ($res['usage']['prompt_tokens'] ?? 0);
        $this->outputTokens += (int) ($res['usage']['completion_tokens'] ?? 0);

        $choice = $res['choices'][0] ?? [];
        $raw = (string) ($choice['message']['content'] ?? '');
        $data = json_decode($raw, true);
        if (($choice['finish_reason'] ?? '') === 'length' || !is_array($data)) {
            $this->truncated = $this->truncated || ($choice['finish_reason'] ?? '') === 'length';
            $this->extraFlags[] = ['severity' => 'critical', 'field' => null,
                'message' => "Could not read $range: the model's answer was cut off or was not valid JSON. "
                    . 'Nothing from these pages is included. Try again, or raise GROQ_MAX_TOKENS on a paid plan.'];
            return null;
        }

        // Fill in page numbers the model left out.
        foreach ($data['fields'] ?? [] as $i => $f) {
            if (($f['page'] ?? null) === null && count($pages) === 1) {
                $data['fields'][$i]['page'] = $pages[0];
            }
        }
        foreach ($data['tables'] ?? [] as $i => $t) {
            if (($t['page'] ?? null) === null) {
                $data['tables'][$i]['page'] = $pages[0];
            }
        }
        return $data;
    }

    /** POST with retries: waits out rate limits, and drops reasoning_effort if the model rejects it. */
    private function call(array $payload): array
    {
        $headers = ['Authorization' => 'Bearer ' . Env::require('GROQ_API_KEY')];
        $timeout = (int) Env::get('LLM_TIMEOUT_SECONDS', '300');
        for ($attempt = 1; ; $attempt++) {
            try {
                return HttpClient::json('POST', rtrim(Env::get('GROQ_BASE_URL', self::URL), '/'), $headers, $payload, $timeout);
            } catch (HttpException $e) {
                $msg = $e->getMessage();
                if ($e->status === 400 && isset($payload['reasoning_effort']) && stripos($msg, 'reasoning') !== false) {
                    unset($payload['reasoning_effort']);
                    continue;
                }
                if ($e->status === 413 || ($e->status === 429 && stripos($msg, 'request too large') !== false)) {
                    throw new HttpException(413, 'Groq says this request is larger than your plan allows per minute. '
                        . 'Lower GROQ_MAX_TOKENS or GROQ_TEXT_CHUNK_TOKENS. Groq said: ' . $msg);
                }
                if ($e->status === 429 && $attempt <= 6 && stripos($msg, 'per day') === false) {
                    sleep(self::retryAfter($msg));
                    continue;
                }
                if ($e->status === 429) {
                    throw new HttpException(429, 'Groq rate limit reached. Groq said: ' . $msg);
                }
                throw $e;
            }
        }
    }

    /** Parses "Please try again in 1m2.5s" / "7.66s" / "450ms" from a Groq 429 message. */
    public static function retryAfter(string $msg): int
    {
        if (!preg_match('/try again in\s+((?:\d+h)?(?:\d+m(?!s))?(?:[\d.]+m?s)?)/i', $msg, $m) || $m[1] === '') {
            return 20;
        }
        $s = 0.0;
        if (preg_match('/(\d+)h/', $m[1], $x)) { $s += 3600 * (int) $x[1]; }
        if (preg_match('/(\d+)m(?!s)/', $m[1], $x)) { $s += 60 * (int) $x[1]; }
        if (preg_match('/([\d.]+)ms$/', $m[1], $x)) { $s += (float) $x[1] / 1000; }
        elseif (preg_match('/([\d.]+)s$/', $m[1], $x)) { $s += (float) $x[1]; }
        return (int) min(90, max(1, ceil($s) + 1));
    }

    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3.2);
    }

    /**
     * Strict Structured Outputs need every property listed as required and
     * additionalProperties: false. Optional properties become nullable instead.
     */
    public static function strictSchema(array $s): array
    {
        if (($s['type'] ?? null) === 'object' || isset($s['properties'])) {
            $required = $s['required'] ?? [];
            foreach ($s['properties'] ?? [] as $k => $p) {
                $p = self::strictSchema($p);
                if (!in_array($k, $required, true)) {
                    $types = (array) ($p['type'] ?? []);
                    if ($types !== [] && !in_array('null', $types, true)) {
                        $p['type'] = array_merge($types, ['null']);
                        if (isset($p['enum'])) {
                            $p['enum'][] = null;
                        }
                    }
                }
                $s['properties'][$k] = $p;
            }
            $s['required'] = array_keys($s['properties'] ?? []);
            $s['additionalProperties'] = false;
        }
        if (isset($s['items']) && is_array($s['items'])) {
            $s['items'] = self::strictSchema($s['items']);
        }
        return $s;
    }

    /**
     * Merges per-chunk extractions in page order. Tables that continue across pages
     * (same columns) are joined, so running-balance checks still work end to end.
     * @param list<array> $parts
     */
    public static function merge(array $parts): array
    {
        $out = ['document_type' => null, 'title' => null, 'summary' => null, 'issuer' => null,
            'page_count' => null, 'fields' => [], 'tables' => [], 'sections' => [], 'flags' => []];
        $fieldIndex = [];
        $norm = fn (array $cols) => array_map(fn ($c) => strtolower(preg_replace('/\W+/', '', (string) $c)), $cols);

        foreach ($parts as $p) {
            foreach (['document_type', 'title', 'summary', 'issuer'] as $k) {
                if (($out[$k] === null || $out[$k] === '' || $out[$k] === 'other') && !empty($p[$k])) {
                    $out[$k] = $p[$k];
                }
            }
            foreach ($p['fields'] ?? [] as $f) {
                $key = (string) ($f['key'] ?? $f['label'] ?? '');
                if (!isset($fieldIndex[$key])) {
                    $fieldIndex[$key] = count($out['fields']);
                    $out['fields'][] = $f;
                } elseif (($out['fields'][$fieldIndex[$key]]['value'] ?? null) === null && ($f['value'] ?? null) !== null) {
                    $out['fields'][$fieldIndex[$key]] = $f;
                }
            }
            foreach ($p['tables'] ?? [] as $t) {
                $cols = $t['columns'] ?? [];
                $rows = array_values(array_filter($t['rows'] ?? [], fn ($r) => $norm((array) $r) !== $norm($cols)));
                $last = array_key_last($out['tables']);
                if ($last !== null && $cols !== [] && $norm($out['tables'][$last]['columns']) === $norm($cols)) {
                    array_push($out['tables'][$last]['rows'], ...$rows);
                } else {
                    $t['rows'] = $rows;
                    $out['tables'][] = $t;
                }
            }
            array_push($out['sections'], ...($p['sections'] ?? []));
            array_push($out['flags'], ...($p['flags'] ?? []));
        }

        // Drop "missing value" flags for fields another page did fill in, and duplicate flags.
        $filled = [];
        foreach ($out['fields'] as $f) {
            if (($f['value'] ?? null) !== null) {
                $filled[(string) ($f['key'] ?? '')] = true;
            }
        }
        $seen = [];
        $out['flags'] = array_values(array_filter($out['flags'], function ($f) use (&$seen, $filled) {
            $id = strtolower(trim((string) ($f['message'] ?? '')));
            if (isset($seen[$id]) || (($f['field'] ?? null) !== null && isset($filled[$f['field']]) && preg_match('/not printed|missing|not found|not available/i', (string) $f['message']))) {
                return false;
            }
            return $seen[$id] = true;
        }));

        $out['document_type'] ??= 'other';
        $out['title'] ??= 'Untitled document';
        $out['summary'] ??= '';
        return $out;
    }
}
