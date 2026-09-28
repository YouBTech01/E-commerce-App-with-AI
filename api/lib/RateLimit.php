<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Fixed-window rate limiter backed by MySQL (works across PHP workers).
 *
 * Security note: buckets are keyed by verified user id (or client IP for
 * pre-auth endpoints), so one user cannot exhaust another's allowance.
 * Windows are short (seconds); stale rows are cleaned opportunistically.
 */
final class RateLimit
{
    /** @throws ApiError(429) when the bucket exceeds $max hits per window */
    public static function hit(string $bucket, int $max, int $windowSeconds): void
    {
        if ($max <= 0) {
            return; // disabled
        }
        $window = intdiv(time(), max(1, $windowSeconds)) * max(1, $windowSeconds);
        $key = substr($bucket, 0, 120);

        Db::run(
            'INSERT INTO rate_limits (bucket_key, window_start, hits) VALUES (?,?,1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, $window]
        );
        $row = Db::one('SELECT hits FROM rate_limits WHERE bucket_key = ? AND window_start = ?', [$key, $window]);
        $hits = (int) ($row['hits'] ?? 0);

        // Opportunistic cleanup of expired windows (cheap, random 2%).
        if (random_int(1, 100) === 1) {
            Db::run('DELETE FROM rate_limits WHERE window_start < ?', [time() - 3600]);
        }

        if ($hits > $max) {
            $retry = ($window + max(1, $windowSeconds)) - time();
            throw new ApiError(
                'rate_limited',
                'Too many requests — please wait ' . max(1, $retry) . 's and try again.',
                429
            );
        }
    }

    /** Convenience: per-user bucket for an endpoint. */
    public static function forUser(string $scope, int $userId, int $max, int $windowSeconds = 60): void
    {
        self::hit($scope . ':u' . $userId, $max, $windowSeconds);
    }
}
