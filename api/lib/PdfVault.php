<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * PDF Vault — secure ownership + in-app viewer.
 *
 * Security model (server is the only authority):
 *   verified Firebase user -> MySQL user -> active pdf_access (paid order) ->
 *   short-lived viewer session (hashed token) -> stream bytes.
 *
 * - PDFs are never exposed at a public URL.
 * - Viewer tokens are high-entropy, stored only as SHA-256 hashes, short-lived.
 * - Every protected access RE-VALIDATES the underlying access row, so revoked,
 *   refunded or expired access stops working immediately.
 * - Unauthorized attempts are logged.
 * - The dynamic purchaser watermark is rendered by the viewer from data we return.
 */
final class PdfVault
{
    /** Grant (or re-activate) ownership access. Called only after an order is PAID. */
    public static function grant(int $userId, int $productId, ?int $orderId = null): void
    {
        $product = Db::one('SELECT id, access_duration_days FROM products WHERE id = ?', [$productId]);
        if ($product === null) {
            throw new ApiError('not_found', 'Product not found', 404);
        }
        $days = (int) $product['access_duration_days'];
        $expiresAt = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

        Db::run(
            'INSERT INTO pdf_access (user_id, product_id, order_id, status, granted_at, expires_at)
             VALUES (?,?,?,?,NOW(),?)
             ON DUPLICATE KEY UPDATE
                status = \'active\', order_id = VALUES(order_id), granted_at = NOW(),
                expires_at = VALUES(expires_at), revoked_at = NULL, revoked_reason = NULL',
            [$userId, $productId, $orderId, 'active', $expiresAt]
        );
        self::log($userId, $productId, null, 'granted', ['order_id' => $orderId]);
    }

    /** Revoke ownership access and invalidate all its viewer sessions. */
    public static function revoke(int $userId, int $productId, string $reason = 'revoked'): void
    {
        $access = Db::one('SELECT id FROM pdf_access WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
        if ($access === null) {
            return;
        }
        Db::run(
            'UPDATE pdf_access SET status = \'revoked\', revoked_at = NOW(), revoked_reason = ? WHERE id = ?',
            [$reason, (int) $access['id']]
        );
        Db::run('UPDATE viewer_sessions SET revoked = 1 WHERE access_id = ? AND revoked = 0', [(int) $access['id']]);
        self::log($userId, $productId, (int) $access['id'], 'revoked_attempt', ['reason' => $reason]);
    }

    /** Return the active, non-expired access row or null. */
    public static function accessRow(int $userId, int $productId): ?array
    {
        $row = Db::one(
            'SELECT * FROM pdf_access WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]
        );
        if ($row === null) {
            return null;
        }
        if ($row['status'] !== 'active') {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            // Lazily mark expired.
            Db::run('UPDATE pdf_access SET status = \'expired\' WHERE id = ?', [(int) $row['id']]);
            self::log($userId, $productId, (int) $row['id'], 'expired', []);
            return null;
        }
        return $row;
    }

    /** Convenience check used by Study AI and library listings. */
    public static function hasAccess(int $userId, int $productId): bool
    {
        return self::accessRow($userId, $productId) !== null;
    }

    /**
     * Issue a short-lived viewer session after verifying ownership.
     * Returns the RAW token (shown to client once) + watermark + policy.
     */
    public static function issueViewerSession(int $userId, int $productId): array
    {
        $access = self::accessRow($userId, $productId);
        if ($access === null) {
            self::logDenied($userId, $productId);
            throw new ApiError('no_access', 'You do not have access to this document', 403);
        }
        $product = Db::one(
            'SELECT id, title, page_count, download_allowed, is_published FROM products WHERE id = ?',
            [$productId]
        );
        if ($product === null || (int) $product['is_published'] !== 1) {
            throw new ApiError('not_found', 'Document not available', 404);
        }
        $user = Db::one('SELECT id, name, email FROM users WHERE id = ?', [$userId]);

        $raw = random_hex(32);
        $hash = hash('sha256', $raw);
        $ttl = max(60, VIEWER_SESSION_TTL);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        Db::run(
            'INSERT INTO viewer_sessions (access_id, user_id, product_id, token_hash, expires_at, ip, user_agent)
             VALUES (?,?,?,?,?,?,?)',
            [
                (int) $access['id'], $userId, $productId, $hash, $expiresAt,
                ip_to_binary(client_ip()), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );

        self::log($userId, $productId, (int) $access['id'], 'session_issued', ['ttl' => $ttl]);

        return [
            'viewer_token'    => $raw,
            'expires_at'      => $expiresAt,
            'expires_in'      => $ttl,
            'product'         => [
                'id'           => (int) $product['id'],
                'title'        => (string) $product['title'],
                'page_count'   => (int) $product['page_count'],
                'download_allowed' => (bool) $product['download_allowed'],
            ],
            'watermark'       => [
                'name'  => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'userId' => $userId,
                'issuedAt' => date('c'),
            ],
        ];
    }

    /**
     * Resolve a raw viewer token to a validated session. Re-validates the access
     * row on every call so revocation/expiry takes effect immediately.
     */
    public static function resolveViewerSession(string $rawToken): array
    {
        $hash = hash('sha256', $rawToken);
        $session = Db::one('SELECT * FROM viewer_sessions WHERE token_hash = ?', [$hash]);
        if ($session === null) {
            self::logDenied(null, null, 'unknown_token');
            throw new ApiError('invalid_viewer_session', 'Invalid viewer session', 401);
        }
        if ((int) $session['revoked'] === 1) {
            self::logDenied((int) $session['user_id'], (int) $session['product_id'], 'revoked_session');
            throw new ApiError('viewer_session_revoked', 'Viewer session has been revoked', 401);
        }
        if (strtotime((string) $session['expires_at']) < time()) {
            self::logDenied((int) $session['user_id'], (int) $session['product_id'], 'expired_session');
            throw new ApiError('viewer_session_expired', 'Viewer session expired', 401);
        }
        // Re-validate ownership (revoked/refunded/expired access must fail here).
        $access = Db::one('SELECT * FROM pdf_access WHERE id = ?', [(int) $session['access_id']]);
        if ($access === null || $access['status'] !== 'active') {
            self::logDenied((int) $session['user_id'], (int) $session['product_id'], 'access_inactive');
            throw new ApiError('no_access', 'Access is no longer active', 403);
        }
        if ($access['expires_at'] !== null && strtotime((string) $access['expires_at']) < time()) {
            Db::run('UPDATE pdf_access SET status = \'expired\' WHERE id = ?', [(int) $access['id']]);
            self::logDenied((int) $session['user_id'], (int) $session['product_id'], 'access_expired');
            throw new ApiError('no_access', 'Access has expired', 403);
        }
        $product = Db::one('SELECT id, title, pdf_path, is_published FROM products WHERE id = ?', [(int) $session['product_id']]);
        if ($product === null || (int) $product['is_published'] !== 1) {
            throw new ApiError('not_found', 'Document not available', 404);
        }
        Db::run('UPDATE viewer_sessions SET last_used_at = NOW() WHERE id = ?', [(int) $session['id']]);
        return [
            'user_id'    => (int) $session['user_id'],
            'product_id' => (int) $session['product_id'],
            'access_id'  => (int) $session['access_id'],
            'product'    => $product,
        ];
    }

    /** Resolve the absolute filesystem path for a product's protected PDF. */
    public static function resolveFilePath(string $pdfPath): ?string
    {
        $candidates = [];
        if ($pdfPath !== '' && ($pdfPath[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $pdfPath))) {
            $candidates[] = $pdfPath; // absolute
        } else {
            $candidates[] = PDFS_DIR . '/' . ltrim($pdfPath, '/');
        }
        foreach ($candidates as $path) {
            // Prevent path traversal outside the PDFs directory.
            $real = realpath($path);
            if ($real !== false && is_file($real)) {
                $base = realpath(PDFS_DIR);
                if ($base !== false && strncmp($real, $base, strlen($base)) === 0) {
                    return $real;
                }
                // Absolute configured paths are allowed only if explicitly absolute in DB.
                if ($path === $pdfPath && ($pdfPath[0] === '/' )) {
                    return $real;
                }
            }
        }
        return null;
    }

    /**
     * Stream the PDF bytes for a validated viewer session, with HTTP Range support
     * (PDF.js issues range requests). Never reveals the public path.
     */
    public static function stream(string $rawToken): void
    {
        $session = self::resolveViewerSession($rawToken);
        $file = self::resolveFilePath((string) $session['product']['pdf_path']);
        if ($file === null) {
            self::logDenied($session['user_id'], $session['product_id'], 'file_missing');
            throw new ApiError('not_found', 'Document file unavailable', 404);
        }
        $size = (int) filesize($file);
        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }
            if ($m[2] !== '') {
                $end = (int) $m[2];
            }
            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
            if ($end >= $size) {
                $end = $size - 1;
            }
            $status = 206;
            self::log($session['user_id'], $session['product_id'], $session['access_id'], 'range', ['start' => $start, 'end' => $end]);
        } else {
            self::log($session['user_id'], $session['product_id'], $session['access_id'], 'stream', []);
        }

        $length = $end - $start + 1;
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/pdf');
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . $length);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            if ($status === 206) {
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            }
        }
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            http_response_code(500);
            exit;
        }
        fseek($fh, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $chunk = fread($fh, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            @ob_flush();
            flush();
        }
        fclose($fh);
        exit;
    }

    private static function log(int $userId, ?int $productId, ?int $accessId, string $event, array $meta): void
    {
        Db::run(
            'INSERT INTO pdf_access_logs (user_id, product_id, access_id, event, ip, user_agent, meta)
             VALUES (?,?,?,?,?,?,?)',
            [
                $userId, $productId, $accessId, $event,
                ip_to_binary(client_ip()), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private static function logDenied(?int $userId, ?int $productId, string $reason = 'no_access'): void
    {
        self::log($userId ?? 0, $productId, null, 'denied', ['reason' => $reason, 'ip' => client_ip()]);
    }
}
