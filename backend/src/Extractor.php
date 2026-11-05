<?php
declare(strict_types=1);

namespace App;

/**
 * Sends a document (PDF or image) to an LLM and gets back structured JSON:
 * auto-detected fields, tables, readable sections and the model's own flags.
 *
 * LLM_PROVIDER picks the backend: "groq" (default, free tier; see GroqExtractor) or "anthropic".
 * Both return the same JSON shape, forced by a schema.
 * Set MOCK_EXTRACTION=true to skip the API (returns a fixture) for UI testing.
 */
final class Extractor
{
    public const SUPPORTED = [
        'application/pdf' => 'document',
        'image/png' => 'image',
        'image/jpeg' => 'image',
        'image/webp' => 'image',
        'image/gif' => 'image',
    ];

    public const SYSTEM = <<<TXT
You are a document data-extraction engine. You read one document and record what is printed on it.

Hard rules:
1. Extract ONLY what is printed. Never calculate, total, infer, estimate or "fix" a value.
   If a total, date or identifier is not printed, do not create it.
2. Copy values exactly as printed (keep Indian digit grouping like 1,23,456.78, keep Dr/Cr markers,
   keep date formats). Do not convert units or currencies.
3. If a value is expected for this kind of document but is not printed or is illegible,
   include the field with value null and add a flag explaining why.
4. Detect the fields yourself based on the document type. Use clear human labels
   ("Account Number", "GSTIN of Supplier"), and snake_case keys.
5. Tables: reproduce every row of every table, in order, with the printed column headers.
   Never skip, merge or summarise rows. Empty cells are null.
6. Sections: rewrite the document's text into clean, well-structured Markdown in reading order
   (headings, short paragraphs, bullet lists). Remove scan noise and broken line-wraps,
   but do not drop or change any information. Do not repeat large tables here; write
   "(see table: <title>)" where a table sits.
7. Confidence per field: high = clearly printed and unambiguous; medium = readable but
   faint/partly ambiguous; low = guessed between options or partly illegible.
8. Flags: report anything a reviewer must check - illegible text, ambiguous characters
   (0/O, 1/l, 5/S), values that appear inconsistent with each other ON THE PAGE,
   missing pages, handwriting, stamps/overwriting, cut-off edges.
   Do not perform arithmetic to create flags; arithmetic checks are done separately.
9. The summary is 1-3 sentences describing what the document is. Do not put numbers in it
   that are not printed in the document.
TXT;

    public static function toolSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        return [
            'name' => 'record_extraction',
            'description' => 'Record everything extracted from the document.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['document_type', 'title', 'summary', 'fields', 'tables', 'sections', 'flags'],
                'properties' => [
                    'document_type' => ['type' => 'string', 'description' => 'e.g. bank_statement, gst_invoice, gst_return, itr, salary_slip, utility_bill, other'],
                    'title' => ['type' => 'string', 'description' => 'Short title, e.g. "Bank statement - Demo Bank - Sep 2026"'],
                    'summary' => ['type' => 'string'],
                    'issuer' => $nullableString,
                    'page_count' => ['type' => ['integer', 'null']],
                    'fields' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['key', 'label', 'value', 'value_type', 'confidence'],
                            'properties' => [
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'group' => ['type' => 'string', 'description' => 'Logical group, e.g. "Account details", "Summary", "Supplier"'],
                                'value' => $nullableString,
                                'value_type' => ['type' => 'string', 'enum' => ['text', 'number', 'currency', 'date', 'percentage', 'identifier']],
                                'currency' => $nullableString,
                                'page' => ['type' => ['integer', 'null']],
                                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                                'source_text' => ['type' => ['string', 'null'], 'description' => 'The exact printed text the value came from'],
                            ],
                        ],
                    ],
                    'tables' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['title', 'columns', 'rows'],
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'page' => ['type' => ['integer', 'null']],
                                'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $nullableString]],
                            ],
                        ],
                    ],
                    'sections' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['heading', 'body_markdown'],
                            'properties' => [
                                'heading' => ['type' => 'string'],
                                'body_markdown' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'flags' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['severity', 'message'],
                            'properties' => [
                                'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'critical']],
                                'field' => $nullableString,
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{extraction: array, meta: array}
     */
    public function extract(string $bytes, string $mime, string $filename, ?callable $onProgress = null): array
    {
        if (Env::bool('MOCK_EXTRACTION')) {
            $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/bank_statement_extraction.json'), true);
            return ['extraction' => $fixture, 'meta' => ['model' => 'mock', 'stop_reason' => 'tool_use', 'input_tokens' => 0, 'output_tokens' => 0]];
        }

        $kind = self::SUPPORTED[$mime] ?? null;
        if ($kind === null) {
            throw new HttpException(415, "Unsupported file type: $mime");
        }

        $provider = strtolower(Env::get('LLM_PROVIDER', 'groq'));
        if ($provider === 'groq') {
            return (new GroqExtractor())->extract($bytes, $mime, $filename, $onProgress);
        }
        if ($provider !== 'anthropic') {
            throw new HttpException(500, "Server misconfigured: LLM_PROVIDER must be groq or anthropic, not $provider");
        }
        $source = ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)];
        $model = Env::get('ANTHROPIC_MODEL', 'claude-sonnet-5-5');

        $payload = [
            'model' => $model,
            'max_tokens' => (int) Env::get('LLM_MAX_TOKENS', '16000'),
            'system' => self::SYSTEM,
            'tools' => [self::toolSchema()],
            'tool_choice' => ['type' => 'tool', 'name' => 'record_extraction'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => $kind, 'source' => $source],
                    ['type' => 'text', 'text' => "File name: $filename\nExtract this document following the rules. Record the result with the record_extraction tool."],
                ],
            ]],
        ];

        $res = HttpClient::json('POST', 'https://api.anthropic.com/v1/messages', [
            'x-api-key' => Env::require('ANTHROPIC_API_KEY'),
            'anthropic-version' => '2023-06-01',
        ], $payload, (int) Env::get('LLM_TIMEOUT_SECONDS', '300'));

        $input = null;
        foreach ($res['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'record_extraction') {
                $input = $block['input'];
                break;
            }
        }
        if (!is_array($input)) {
            throw new HttpException(502, 'The model did not return a structured extraction');
        }

        return [
            'extraction' => $input,
            'meta' => [
                'model' => $res['model'] ?? $model,
                'stop_reason' => $res['stop_reason'] ?? null,
                'input_tokens' => $res['usage']['input_tokens'] ?? null,
                'output_tokens' => $res['usage']['output_tokens'] ?? null,
            ],
        ];
    }
}
