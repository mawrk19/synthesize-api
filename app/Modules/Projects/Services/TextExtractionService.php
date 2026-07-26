<?php

namespace App\Modules\Projects\Services;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

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

        $text = @file_get_contents($absolutePath);

        if (! is_string($text) || blank(trim($text))) {
            throw new RuntimeException("Unsupported file type for text extraction: {$ext}");
        }

        return trim($text);
    }

    private function extractPdf(string $absolutePath): string
    {
        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($absolutePath);
            $text = (string) $pdf->getText();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not parse PDF: '.$e->getMessage().'. Try a text-based PDF or upload a .txt/.md export.',
                previous: $e,
            );
        }

        $text = $this->repairSpacedGlyphs($text);
        $text = $this->normalizeExtractedText($text);

        if (blank($text)) {
            throw new RuntimeException(
                'PDF contained no extractable text (it may be a scanned/image-only PDF). Export to .txt/.md or use an OCR tool first.'
            );
        }

        if ($this->looksLikeGibberish($text)) {
            throw new RuntimeException(
                'PDF text extraction produced unreadable content (likely encoded/scanned PDF). Export to .txt/.md or re-save as a text PDF.'
            );
        }

        return mb_substr($text, 0, 50000);
    }

    /**
     * Firefox/macOS Quartz print PDFs often emit letters separated by tabs:
     * "S\tt\to\tf\tt\tw\ta\tr\te" → "Software"
     */
    private function repairSpacedGlyphs(string $text): string
    {
        $tabCount = substr_count($text, "\t");
        $sampleLen = max(1, min(strlen($text), 4000));
        $tabRatio = $tabCount / $sampleLen;

        if ($tabRatio < 0.08) {
            // Also handle space-separated single letters on many lines.
            return $this->joinSpaceSeparatedLetters($text);
        }

        // Remove tabs between non-whitespace glyphs, keep newlines.
        $text = preg_replace('/(?<=\S)\t+(?=\S)/u', '', $text) ?? $text;
        // Remaining tabs → spaces
        $text = str_replace("\t", ' ', $text);
        // Insert spaces at camel boundaries created by joining TitleCase words.
        $text = preg_replace('/(?<=[a-z])(?=[A-Z])/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<=[A-Za-z])(?=\d)/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<=\d)(?=[A-Za-z])/u', ' ', $text) ?? $text;

        // Quartz PDFs often mix tabs and spaces between glyphs — join those next.
        return $this->joinSpaceSeparatedLetters($text);
    }

    private function joinSpaceSeparatedLetters(string $text): string
    {
        $lines = preg_split("/\n/", $text) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $out[] = '';

                continue;
            }

            // Collapse "S o f t w a r e" style runs inside the line.
            $collapsed = preg_replace_callback(
                '/(?:(?<=^)|(?<=\s))(?:[\p{L}\p{N}\.\-\(\)]\s+){3,}[\p{L}\p{N}\.\-\(\)](?=\s|$)/u',
                function (array $m): string {
                    $chunk = $m[0];
                    $joined = preg_replace('/\s+/', '', $chunk) ?? $chunk;
                    $joined = preg_replace('/(?<=[a-z])(?=[A-Z])/u', ' ', $joined) ?? $joined;
                    $joined = preg_replace('/(?<=[A-Za-z])(?=\d)/u', ' ', $joined) ?? $joined;
                    $joined = preg_replace('/(?<=\d)(?=[A-Za-z])/u', ' ', $joined) ?? $joined;

                    return $joined;
                },
                $trimmed,
            ) ?? $trimmed;

            $tokens = preg_split('/\s+/', $collapsed) ?: [];
            $single = 0;
            foreach ($tokens as $token) {
                if (mb_strlen($token) === 1) {
                    $single++;
                }
            }

            if (count($tokens) >= 6 && ($single / count($tokens)) > 0.55) {
                $joined = implode('', $tokens);
                $joined = preg_replace('/(?<=[a-z])(?=[A-Z])/u', ' ', $joined) ?? $joined;
                $joined = preg_replace('/(?<=[A-Za-z])(?=\d)/u', ' ', $joined) ?? $joined;
                $joined = preg_replace('/(?<=\d)(?=[A-Za-z])/u', ' ', $joined) ?? $joined;
                $out[] = $joined;
            } else {
                $out[] = $collapsed;
            }
        }

        $joined = implode("\n", $out);

        // Second pass: collapse leftover short glyph runs ("Ge e r y" → "Geery").
        return preg_replace_callback(
            '/\b(?:[\p{L}]{1,2}\s+){2,}[\p{L}]{1,2}\b/u',
            function (array $m): string {
                return preg_replace('/\s+/', '', $m[0]) ?? $m[0];
            },
            $joined,
        ) ?? $joined;
    }

    private function normalizeExtractedText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Detect leftover broken scrape: mostly single-character tokens.
     */
    private function looksLikeGibberish(string $text): bool
    {
        $sample = mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 800);
        if (mb_strlen($sample) < 40) {
            return false;
        }

        $tokens = preg_split('/\s+/', $sample) ?: [];
        if (count($tokens) < 20) {
            return false;
        }

        $singleChar = 0;
        foreach ($tokens as $token) {
            if (mb_strlen($token) === 1) {
                $singleChar++;
            }
        }

        return ($singleChar / max(1, count($tokens))) > 0.55;
    }
}
