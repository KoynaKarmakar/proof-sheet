<?php
// php tests/run.php  — offline checks for validator + excel export (no API keys needed)
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Validator; use App\ExcelExporter; use App\GroqExtractor; use App\Extractor; use App\TableExtractor;

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? "  PASS " : "  FAIL ") . "$m\n"; if (!$c) $fail++; };

echo "parseNumber\n";
$ok(Validator::parseNumber('4,12,850.75') === 412850.75, 'indian grouping');
$ok(Validator::parseNumber('₹ 1,200.00') === 1200.0, 'rupee symbol');
$ok(Validator::parseNumber('(1,200.00)') === -1200.0, 'parentheses negative');
$ok(Validator::parseNumber('1,200.00 Dr') === 1200.0, 'Dr suffix');
$ok(Validator::parseNumber('18%') === 18.0, 'percent');
$ok(Validator::parseNumber('N/A') === null, 'non-number');

$fx = json_decode(file_get_contents(__DIR__ . '/fixtures/bank_statement_extraction.json'), true);
echo "validator on clean statement\n";
$r = Validator::process($fx, ['stop_reason' => 'tool_use']);
$msgs = array_column($r['flags'], 'message');
$ok($r['flag_counts']['critical'] === 0, 'no critical flags');
$ok((bool) preg_grep('/Running balance verified/', $msgs), 'running balance verified');
$ok(count(preg_grep('/Printed total matches/', $msgs)) === 2, 'printed totals match table');
$ok((bool) preg_grep('/Not available/', $msgs), 'missing PAN flagged');
$ok($r['tables'][0]['numeric_columns'] === [3, 4, 5], 'numeric columns detected (not ref no.)');

echo "validator on tampered statement\n";
$bad = $fx; $bad['tables'][0]['rows'][5][5] = '9,99,999.00';
$r2 = Validator::process($bad, ['stop_reason' => 'max_tokens']);
$m2 = array_column($r2['flags'], 'message');
$ok((bool) preg_grep('/does not reconcile/', $m2), 'misread balance caught');
$ok((bool) preg_grep('/output limit/', $m2), 'truncation flagged');
$ok($r2['flags'][0]['severity'] === 'critical', 'critical sorted first');

echo "invoice total check\n";
$inv = ['fields' => [
  ['label' => 'Taxable Value', 'value' => '2,83,800.00', 'value_type' => 'currency', 'confidence' => 'high'],
  ['label' => 'IGST @ 18%', 'value' => '51,084.00', 'value_type' => 'currency', 'confidence' => 'high'],
  ['label' => 'Grand Total (INR)', 'value' => '3,34,884.00', 'value_type' => 'currency', 'confidence' => 'high'],
]];
$ok((bool) preg_grep('/Grand total matches/', array_column(Validator::process($inv)['flags'], 'message')), 'invoice reconciles');
$inv['fields'][2]['value'] = '3,35,884.00';
$ok(Validator::process($inv)['flag_counts']['critical'] === 1, 'invoice mismatch is critical');


echo "identifier formats\n";
$ok(Validator::checkIdentifierFormat('Supplier GSTIN', '27AABCD1234E1Z5') === null, 'valid GSTIN passes');
$ok(Validator::checkIdentifierFormat('Supplier GSTIN', '27AAABCD1234E1Z5') !== null, 'GSTIN with extra letter caught');
$ok(Validator::checkIdentifierFormat('IFSC Code', 'DEMO0001234') === null, 'valid IFSC passes');
$ok(Validator::checkIdentifierFormat('IFSC Code', 'DEMO00001234') !== null, 'IFSC with extra digit caught');
$ok(Validator::checkIdentifierFormat('PAN', 'ABCDE1234F') === null && Validator::checkIdentifierFormat('PAN', 'ABCDE12345') !== null, 'PAN format');
$ok(Validator::checkIdentifierFormat('Company Name', 'Anything') === null, 'other labels ignored');

echo "groq helpers\n";
$st = GroqExtractor::strictSchema(Extractor::toolSchema()['input_schema']);
$ok($st['additionalProperties'] === false && in_array('issuer', $st['required'], true), 'strict schema: all top-level keys required');
$fs = $st['properties']['fields']['items'];
$ok($fs['properties']['group']['type'] === ['string', 'null'] && $fs['additionalProperties'] === false, 'strict schema: optional field made nullable');
$ok(GroqExtractor::retryAfter('Please try again in 7.66s.') === 9, 'retry after seconds');
$ok(GroqExtractor::retryAfter('Please try again in 1m2.5s.') === 64, 'retry after minutes');
$ok(GroqExtractor::retryAfter('Please try again in 450ms.') === 2, 'retry after ms');
$t1 = $fx['tables'][0]; $half = intdiv(count($t1['rows']), 2);
$p1 = $fx; $p1['tables'][0]['rows'] = array_slice($t1['rows'], 0, $half);
$p2 = $fx; $p2['tables'][0]['rows'] = array_merge([$t1['columns']], array_slice($t1['rows'], $half));
$p2['fields'] = []; $p2['flags'] = [];
$m = GroqExtractor::merge([$p1, $p2]);
$ok(count($m['tables']) === count($fx['tables']) && $m['tables'][0]['rows'] === $t1['rows'], 'split table rejoined, repeated header dropped');
$ok(Validator::process($m, ['stop_reason' => 'end_turn'])['flag_counts']['critical'] === 0, 'merged statement still reconciles');


echo "table parser (needs poppler)\n";
if (trim((string) shell_exec('command -v pdftotext')) === '') {
    echo "  SKIP pdftotext not installed\n";
} else {
    $px = fn ($f) => TableExtractor::fromBboxXml((string) shell_exec('pdftotext -bbox-layout ' . escapeshellarg(dirname(__DIR__, 2) . "/samples/$f") . ' - 2>/dev/null'));
    $bs = $px('sample_bank_statement.pdf');
    $ok(count($bs['tables']) === 1 && $bs['rows'] === 27, 'statement: one table, 27 rows');
    $ok($bs['tables'][0]['columns'] === ['Date', 'Narration', 'Chq/Ref No.', 'Withdrawal (Dr)', 'Deposit (Cr)', 'Balance'], 'statement: printed column headers');
    $ok($bs['tables'][0]['rows'][1] === ['01-09-2026', 'UPI/DR/126225/ELECTRICITY BOARD', '126225', '41,448.02', null, '3,71,402.73'], 'statement: row cells exact');
    $ok(!str_contains(json_encode($bs['tables']), 'Account Summary') && str_contains($bs['residual'][1], 'Account Summary'), 'statement: summary left for the LLM');
    $vb = Validator::process(['fields' => [], 'tables' => $bs['tables']]);
    $ok((bool) preg_grep('/all 26 transaction rows reconcile/', array_column($vb['flags'], 'message')), 'statement: parsed rows reconcile');
    $inv = $px('sample_gst_invoice.pdf');
    $ok($inv['rows'] === 4 && $inv['tables'][0]['rows'][3] === ['4', 'Air Cooler 40L', '84796000', '12', '6,450.00', '77,400.00', '18%', '13,932.00'], 'invoice: 4 line items, totals kept out');
}
$typed = ['columns' => ['Date', 'Particulars', 'Amount', 'Debit/Credit', 'Balance'], 'rows' => [
    ['01/08/2026', 'x', '100.00', 'CR', '-1,000.00'], ['02/08/2026', 'y', '50.00', 'DR', '-1,050.00'], ['03/08/2026', 'z', '1,050.00', 'CR', '0.00']]];
$ok((bool) preg_grep('/all 2 transaction rows reconcile/', array_column(Validator::process(['fields' => [], 'tables' => [$typed]])['flags'], 'message')), 'balance check: Amount + DR/CR layout');

// A credit read into the Debit column is moved back; a correct row is left alone.
$swap = ['title' => 'Txns', 'columns' => ['Date', 'Narration', 'Debit', 'Credit', 'Balance'], 'rows' => [
    ['01/10/2026', 'B/F', null, null, '1,000.00'],
    ['02/10/2026', 'Interest', '50.00', null, '1,050.00'],
    ['03/10/2026', 'Shop', '200.00', null, '850.00'],
]];
$sv = Validator::process(['fields' => [], 'tables' => [$swap]]);
$ok($sv['tables'][0]['rows'][1][3] === '50.00' && $sv['tables'][0]['rows'][1][2] === null && $sv['tables'][0]['rows'][2][2] === '200.00', 'swap repair: moves misplaced credit, keeps correct debit');
$ok((bool) preg_grep('/all 2 transaction rows reconcile/', array_column($sv['flags'], 'message')), 'swap repair: balance reconciles after the fix');

echo "excel export\n";
$bytes = ExcelExporter::build(['filename' => 'sample_bank_statement.pdf', 'processed_at' => '2026-11-05T13:00:00Z', 'model' => 'mock', 'extraction' => $r]);
$out = $argv[1] ?? sys_get_temp_dir() . '/test_export.xlsx';
file_put_contents($out, $bytes);
$ok(strlen($bytes) > 2000, "xlsx written ($out, " . strlen($bytes) . " bytes)");

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
