<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * HTTP request parsing + JSON responses. Also carries the ApiError type used
 * across the API to produce consistent, safe error payloads.
 */
final class ApiError extends \RuntimeException
{
    public int $httpStatus;
    public string $errorCode;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }
}

final class Http
{
    /** Decoded JSON body as associative array. */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiError('invalid_body', 'Request body must be valid JSON', 400);
        }
        return $data;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function requireMethod(string ...$allowed): void
    {
        if (!in_array(self::method(), $allowed, true)) {
            throw new ApiError('method_not_allowed', 'Method not allowed', 405);
        }
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @param array<string,mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function ok(array $data = [], int $status = 200): void
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function fail(ApiError $e): void
    {
        self::json([
            'ok' => false,
            'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
        ], $e->httpStatus);
    }

    /** Bearer token from Authorization header. */
    public static function bearerToken(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $h = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
