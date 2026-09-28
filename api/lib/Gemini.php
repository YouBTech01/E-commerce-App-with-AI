<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Minimal server-side Gemini API client. The API key NEVER leaves the server.
 */
final class Gemini
{
    /** @param array<int,array> $parts content parts (text / inline_data)
     *  @param array<int,array{role:string,text:string}> $history prior turns (oldest first)
     *  @return array{text:string,input_tokens:int,output_tokens:int}
     */
    public static function generateContent(array $parts, ?string $systemInstruction = null, ?string $model = null, array $history = []): array
    {
        if (GEMINI_API_KEY === '') {
            throw new ApiError('ai_not_configured', 'AI service is not configured', 503);
        }
        $model = $model ?: GEMINI_MODEL;
        // Multi-turn contents: capped history first, then the current user turn.
        $contents = [];
        foreach (array_slice($history, -12) as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $contents[] = [
                'role'  => (($turn['role'] ?? 'user') === 'model') ? 'model' : 'user',
                'parts' => [['text' => mb_substr($text, 0, 4000)]],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => $parts];
        $body = ['contents' => $contents];
        if ($systemInstruction !== null && $systemInstruction !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }
        $body['generationConfig'] = [
            'temperature' => 0.4,
            'maxOutputTokens' => 2048,
        ];

        $url = GEMINI_API_BASE . '/models/' . rawurlencode($model) . ':generateContent?key=' . GEMINI_API_KEY;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 60,
            // Force IPv4 — some hosts resolve the API to IPv6 first and time out.
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // curl_close() is a no-op on PHP 8+: the handle is released
        // automatically when $ch goes out of scope.

        if ($resp === false) {
            throw new ApiError('ai_error', 'AI service unreachable: ' . $err, 502);
        }
        $json = json_decode((string) $resp, true);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $msg = is_array($json) ? ($json['error']['message'] ?? 'AI request failed') : 'AI request failed';
            throw new ApiError('ai_error', $msg, 502);
        }
        $text = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $usage = $json['usageMetadata'] ?? [];
        return [
            'text' => trim($text),
            'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
        ];
    }

    /** Build an inline_data part for a file (base64). */
    public static function inlinePart(string $mime, string $base64Data): array
    {
        return ['inline_data' => ['mime_type' => $mime, 'data' => $base64Data]];
    }

    public static function textPart(string $text): array
    {
        return ['text' => $text];
    }
}
