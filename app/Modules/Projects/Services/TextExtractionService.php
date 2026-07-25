<?php

namespace App\Modules\Projects\Services;

use RuntimeException;

class TextExtractionService
{
    public function extract(string $absolutePath, string $filename, ?string $mimeType = null): string
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException("Cannot read file: {$filename}");
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, ['txt', 'md', 'markdown', 'csv', 'json', 'php', 'ts', 'tsx', 'js', 'jsx', 'sql'], true)) {
            $text = file_get_contents($absolutePath);

            return is_string($text) ? trim($text) : '';
        }

        if ($ext === 'pdf' || $mimeType === 'application/pdf') {
            return $this->extractPdf($absolutePath);
        }

        // Fallback: try as plain text
        $text = @file_get_contents($absolutePath);

        if (! is_string($text) || blank(trim($text))) {
            throw new RuntimeException("Unsupported file type for text extraction: {$ext}");
        }

        return trim($text);
    }

    private function extractPdf(string $absolutePath): string
    {
        // Lightweight PDF text extraction without external deps:
        // pull printable strings between stream objects. Good enough for most text PDFs.
        $raw = file_get_contents($absolutePath);

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Empty PDF file.');
        }

        $chunks = [];

        if (preg_match_all('/stream\s*(.*?)\s*endstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    $decoded = $stream;
                }

                if (preg_match_all('/\((.*?)\)/s', (string) $decoded, $textMatches)) {
                    foreach ($textMatches[1] as $piece) {
                        $piece = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $piece);
                        $piece = preg_replace('/\\\\(.)/', '$1', $piece) ?? $piece;
                        if (trim($piece) !== '') {
                            $chunks[] = $piece;
                        }
                    }
                }

                if (preg_match_all('/BT\s*(.*?)\s*ET/s', (string) $decoded, $btMatches)) {
                    foreach ($btMatches[1] as $bt) {
                        if (preg_match_all('/\((.*?)\)\s*Tj/s', $bt, $tj)) {
                            foreach ($tj[1] as $piece) {
                                if (trim($piece) !== '') {
                                    $chunks[] = $piece;
                                }
                            }
                        }
                    }
                }
            }
        }

        $text = trim(implode(' ', $chunks));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (blank($text)) {
            throw new RuntimeException('Could not extract text from PDF. Try uploading a .txt/.md export instead.');
        }

        return mb_substr($text, 0, 50000);
    }
}
