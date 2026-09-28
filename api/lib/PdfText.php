<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Best-effort, dependency-free PDF text extractor.
 *
 * Used only as a FALLBACK when a document is too large to send inline to the
 * model. It inflates FlateDecode streams and pulls text-showing operators.
 * Works for many text-based PDFs; scanned/image-only PDFs yield little/no text
 * (those should be sent inline as images instead). Output is length-capped.
 */
final class PdfText
{
    public static function extract(string $filePath, int $maxChars = 20000): string
    {
        $data = @file_get_contents($filePath);
        if ($data === false || $data === '') {
            return '';
        }
        $out = '';
        // Find stream...endstream blocks.
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $raw = $match[1];
                $inflated = @gzuncompress($raw);
                if ($inflated === false) {
                    // Some producers omit zlib header; try raw deflate.
                    $inflated = @gzinflate($raw);
                }
                $content = $inflated !== false ? $inflated : '';
                if ($content !== '' && (strpos($content, 'BT') !== false || strpos($content, 'Tj') !== false || strpos($content, 'TJ') !== false)) {
                    $out .= self::extractTextOperators($content) . "\n";
                    if (strlen($out) >= $maxChars) {
                        break;
                    }
                }
            }
        }
        // Fallback: whole-document text operators (uncompressed).
        if ($out === '') {
            $out = self::extractTextOperators($data);
        }
        $out = preg_replace('/\s+/', ' ', $out) ?? $out;
        return substr(trim($out), 0, $maxChars);
    }

    private static function extractTextOperators(string $content): string
    {
        $text = '';
        // (literal) Tj   and   [ (a) -1 (b) ] TJ
        if (preg_match_all('/\((?:\\.|[^\\\\()])*\)\s*Tj/', $content, $m) > 0) {
            foreach ($m[0] as $tok) {
                $text .= self::decodeLiteralString($tok) . ' ';
            }
        }
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $m) > 0) {
            foreach ($m[1] as $arr) {
                if (preg_match_all('/\((?:\\.|[^\\\\()])*\)/', $arr, $mm) > 0) {
                    foreach ($mm[0] as $lit) {
                        $text .= self::decodeLiteralString($lit);
                    }
                }
                $text .= ' ';
            }
        }
        return $text;
    }

    private static function decodeLiteralString(string $token): string
    {
        if (preg_match('/\((.*)\)/s', $token, $m) !== 1) {
            return '';
        }
        $s = $m[1];
        $s = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s);
        // Common escapes.
        $s = preg_replace('/\\n/', "\n", $s);
        $s = preg_replace('/\\r/', "\r", $s);
        $s = preg_replace('/\\t/', "\t", $s);
        return $s;
    }
}
