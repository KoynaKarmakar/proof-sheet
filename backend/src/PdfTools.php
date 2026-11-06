<?php
declare(strict_types=1);

namespace App;

/**
 * Thin wrapper around poppler-utils (pdfinfo, pdftotext, pdftoppm).
 * Needed by the Groq path, because Groq models cannot read PDF files directly.
 */
final class PdfTools
{
    private string $dir;
    private string $pdf;

    public function __construct(string $bytes)
    {
        foreach (['pdfinfo', 'pdftotext', 'pdftoppm'] as $bin) {
            if (trim((string) shell_exec('command -v ' . $bin . ' 2>/dev/null')) === '') {
                throw new HttpException(500, "Server misconfigured: $bin (poppler-utils) is not installed");
            }
        }
        $this->dir = sys_get_temp_dir() . '/pdf_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->pdf = $this->dir . '/in.pdf';
        file_put_contents($this->pdf, $bytes);
    }

    public function __destruct()
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function pageCount(): int
    {
        $out = (string) shell_exec('pdfinfo ' . escapeshellarg($this->pdf) . ' 2>/dev/null');
        if (!preg_match('/^Pages:\s+(\d+)/m', $out, $m)) {
            throw new HttpException(422, 'Could not read this PDF (it may be damaged or password-protected)');
        }
        return (int) $m[1];
    }

    /** Every word with its position (XHTML), for TableExtractor. Fast: ~0.05 s for 14 pages. */
    public function bboxXml(): string
    {
        return (string) shell_exec('pdftotext -bbox-layout -enc UTF-8 ' . escapeshellarg($this->pdf) . ' - 2>/dev/null');
    }

    /** Printed text of one page, keeping the visual column layout. */
    public function pageText(int $page): string
    {
        $cmd = sprintf('pdftotext -layout -enc UTF-8 -f %d -l %d %s - 2>/dev/null', $page, $page, escapeshellarg($this->pdf));
        return rtrim((string) shell_exec($cmd));
    }

    /** One page rendered as a JPEG (for scanned pages with no text layer). */
    public function pageJpeg(int $page, int $dpi = 150): string
    {
        $base = $this->dir . '/p' . $page;
        $cmd = sprintf('pdftoppm -jpeg -jpegopt quality=85 -r %d -f %d -l %d -singlefile %s %s 2>/dev/null',
            $dpi, $page, $page, escapeshellarg($this->pdf), escapeshellarg($base));
        shell_exec($cmd);
        $bytes = @file_get_contents($base . '.jpg');
        if ($bytes === false || $bytes === '') {
            throw new HttpException(422, "Could not render page $page of the PDF");
        }
        @unlink($base . '.jpg');
        return $bytes;
    }
}
