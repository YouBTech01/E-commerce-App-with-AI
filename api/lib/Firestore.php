<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Real-time secondary mirror (spec §2.2 — Dual-Engine Hybrid Pattern).
 *
 * Dual-writes key entities to Google Cloud Firestore over the REST API using a
 * service-account JWT (RS256 via openssl — no composer dependency required).
 *
 * OPTIONAL & NON-FATAL: mirroring only runs when FIRESTORE_SYNC=1 AND a readable
 * service-account JSON is configured. Any failure is logged and swallowed so a
 * Firestore outage can never take down payments or the app itself.
 * MySQL remains the sole authoritative store.
 */
class Firestore
{
    private static bool $inspected = false;
    private static array $creds = [];
    private static ?string $accessToken = null;

    public static function enabled(): bool
    {
        if (!FIRESTORE_SYNC || FIRESTORE_PROJECT_ID === '' || FIRESTORE_SERVICE_ACCOUNT === '') {
            return false;
        }
        if (!self::$inspected) {
            self::$inspected = true;
            self::$creds = self::loadCredentials();
        }
        return self::$creds !== [];
    }

    private static function loadCredentials(): array
    {
        $path = FIRESTORE_SERVICE_ACCOUNT;
        if ($path === '' || !is_readable($path)) {
            error_log('[ExamLegacy] Firestore: service account not readable: ' . $path);
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            error_log('[ExamLegacy] Firestore: invalid service account JSON');
            return [];
        }
        return $json;
    }

    /** Upsert a document: collection name + document id + scalar/array payload. */
    public static function doc(string $collection, string $id, array $data): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        try {
            $url = 'https://firestore.googleapis.com/v1/projects/'
                . rawurlencode(FIRESTORE_PROJECT_ID)
                . '/databases/(default)/documents/'
                . rawurlencode($collection) . '/' . rawurlencode($id);
            $body = json_encode(['fields' => self::fields($data)], JSON_UNESCAPED_SLASHES);
            $res = self::request('PATCH', $url, (string) $body);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                return true;
            }
            error_log('[ExamLegacy] Firestore mirror HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 300));
            return false;
        } catch (\Throwable $e) {
            error_log('[ExamLegacy] Firestore mirror failed: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------ values

    /** Encode a PHP value into a Firestore `fields` map. */
    private static function fields(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[(string) $k] = ['value' => self::value($v)];
        }
        return $out;
    }

    private static function value(mixed $v): array
    {
        if ($v === null) {
            return ['nullValue' => null];
        }
        if (is_bool($v)) {
            return ['booleanValue' => $v];
        }
        if (is_int($v)) {
            return ['integerValue' => (string) $v];
        }
        if (is_float($v)) {
            return ['doubleValue' => $v];
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return ['arrayValue' => ['values' => array_map([self::class, 'value'], $v)]];
            }
            return ['mapValue' => ['fields' => self::fields($v)]];
        }
        return ['stringValue' => (string) $v];
    }

    // -------------------------------------------------------------------- auth

    private static function accessToken(): string
    {
        if (self::$accessToken !== null) {
            return self::$accessToken;
        }
        $now = time();
        $b64 = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $header  = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $claims  = $b64(json_encode([
            'iss'   => (string) self::$creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/datastore',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $claims;
        $key = openssl_pkey_get_private((string) self::$creds['private_key']);
        if ($key === false || !openssl_sign($unsigned, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Firestore: cannot sign service-account JWT');
        }
        $jwt = $unsigned . '.' . $b64($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            throw new \RuntimeException('Firestore: token exchange failed HTTP ' . $status . ' ' . $err . ' ' . substr((string) $body, 0, 200));
        }
        $json = json_decode((string) $body, true);
        $token = (string) ($json['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('Firestore: no access token in response');
        }
        self::$accessToken = $token;
        return $token;
    }

    private static function request(string $method, string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . self::accessToken(),
                'Content-Type: application/json; charset=UTF-8',
            ],
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            throw new \RuntimeException('Firestore request failed: ' . $err);
        }
        return ['status' => $status, 'body' => (string) $res];
    }
}
