<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Cashfree payments — SERVER-SIDE verification is the only authority.
 *
 * A single payment_intents table drives every paid action (order, wallet
 * recharge, AI credit pack, VIP PASS). The browser success callback is NEVER
 * trusted: we fetch gateway truth (verify) or validate a signed webhook, then
 * settle idempotently. Secrets never leave the server.
 */
final class Payments
{
    private static function baseUrl(): string
    {
        return CASHFREE_ENV === 'production' ? 'https://api.cashfree.com' : 'https://sandbox.cashfree.com';
    }

    private static function configured(): bool
    {
        return CASHFREE_APP_ID !== '' && CASHFREE_SECRET_KEY !== '';
    }

    /** @return array{status:int,body:string,json:?array} */
    private static function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-client-id: ' . CASHFREE_APP_ID,
                'x-client-secret: ' . CASHFREE_SECRET_KEY,
                'x-api-version: ' . CASHFREE_API_VERSION,
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($body === false) {
            throw new ApiError('gateway_error', 'Payment gateway unreachable: ' . $err, 502);
        }
        return ['status' => $status, 'body' => (string) $body, 'json' => json_decode((string) $body, true)];
    }

    private static function requirePhone(int $userId): string
    {
        $phone = Db::scalar('SELECT phone FROM users WHERE id = ?', [$userId]);
        if ($phone === null || $phone === '') {
            throw new ApiError('phone_required', 'Please add a phone number to your account before online payment', 422);
        }
        return (string) $phone;
    }

    /**
     * Create a gateway intent and Cashfree order. Returns the browser session.
     * @return array{intent_id:int,payment_session_id:string,provider_order_id:string}
     */
    public static function createIntent(int $userId, string $kind, int $amountPaise, ?int $refId, array $meta = [], string $idemKey = ''): array
    {
        if (!self::configured()) {
            throw new ApiError('gateway_not_configured', 'Online payments are not configured', 503);
        }
        if ($amountPaise <= 0) {
            throw new ApiError('invalid_amount', 'Amount must be positive', 400);
        }
        if (!in_array($kind, ['order', 'recharge', 'credit_pack', 'vip'], true)) {
            throw new ApiError('invalid_kind', 'Invalid payment kind', 400);
        }
        $phone = self::requirePhone($userId);
        $email = (string) Db::scalar('SELECT email FROM users WHERE id = ?', [$userId]);
        $idemKey = $idemKey !== '' ? $idemKey : ($kind . ':' . $userId . ':' . random_hex(6));

        Db::run(
            'INSERT INTO payment_intents (user_id, kind, ref_id, amount_paise, currency, status, provider, idempotency_key, meta)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$userId, $kind, $refId, $amountPaise, 'INR', 'pending', 'cashfree', $idemKey,
             empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)]
        );
        $intentId = Db::insertId();

        $payload = [
            'order_id'       => 'EL' . $intentId,
            'order_amount'   => round($amountPaise / 100, 2),
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id'    => (string) $userId,
                'customer_email' => $email,
                'customer_phone' => $phone,
            ],
            'order_note' => 'ExamLegacy ' . $kind,
            'order_meta' => ['intent_id' => $intentId, 'kind' => $kind],
        ];
        $res = self::request('POST', '/pg/orders', $payload);
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            Db::run('UPDATE payment_intents SET status = \'failed\' WHERE id = ?', [$intentId]);
            throw new ApiError('gateway_error', 'Could not create payment order', 502);
        }
        $cfOrderId = (string) ($res['json']['cf_order_id'] ?? '');
        $sessionId = (string) ($res['json']['order_token'] ?? $res['json']['payment_session_id'] ?? '');
        if ($cfOrderId === '' || $sessionId === '') {
            Db::run('UPDATE payment_intents SET status = \'failed\' WHERE id = ?', [$intentId]);
            throw new ApiError('gateway_error', 'Invalid gateway response', 502);
        }
        Db::run('UPDATE payment_intents SET provider_order_id = ? WHERE id = ?', [$cfOrderId, $intentId]);
        return ['intent_id' => $intentId, 'payment_session_id' => $sessionId, 'provider_order_id' => $cfOrderId];
    }

    /** Server-side verification by our intent id (belongs to the user). */
    public static function verifyIntent(int $userId, int $intentId): array
    {
        $intent = Db::one('SELECT * FROM payment_intents WHERE id = ? AND user_id = ?', [$intentId, $userId]);
        if ($intent === null) {
            throw new ApiError('not_found', 'Payment not found', 404);
        }
        if ($intent['status'] === 'paid') {
            return ['status' => 'paid', 'kind' => (string) $intent['kind'], 'ref_id' => $intent['ref_id'] !== null ? (int) $intent['ref_id'] : null];
        }
        if (empty($intent['provider_order_id'])) {
            return ['status' => (string) $intent['status'], 'kind' => (string) $intent['kind']];
        }
        $res = self::request('GET', '/pg/orders/' . rawurlencode((string) $intent['provider_order_id']));
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw new ApiError('gateway_error', 'Could not verify payment', 502);
        }
        $data = $res['json'];
        $orderStatus = strtoupper((string) ($data['order_status'] ?? ''));
        if ($orderStatus === 'PAID') {
            self::settle($intent, $data);
            return ['status' => 'paid', 'kind' => (string) $intent['kind'], 'ref_id' => $intent['ref_id'] !== null ? (int) $intent['ref_id'] : null];
        }
        if (in_array($orderStatus, ['EXPIRED', 'CANCELLED'], true)) {
            Db::run('UPDATE payment_intents SET status = \'failed\' WHERE id = ?', [$intentId]);
        }
        return ['status' => strtolower($orderStatus), 'kind' => (string) $intent['kind']];
    }

    /** Admin-triggered recheck: re-verify the order's latest gateway intent. */
    public static function recheckForAdmin(int $orderId, int $adminId): array
    {
        $order = Db::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            throw new ApiError('not_found', 'Order not found', 404);
        }
        $intent = Db::one(
            'SELECT * FROM payment_intents WHERE order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId]
        );
        if ($intent === null || empty($intent['provider_order_id'])) {
            throw new ApiError('no_gateway_session', 'No gateway payment found for this order.', 422);
        }
        $before = (string) $order['status'];
        $result = self::verifyIntent((int) $intent['user_id'], (int) $intent['id']);
        $after = (string) (Db::one('SELECT status FROM orders WHERE id = ?', [$orderId])['status'] ?? $before);
        Admin::audit($adminId, 'order.recheck', 'order', $orderId, ['status' => $before], ['status' => $after, 'gateway' => $result]);
        return ['order_id' => $orderId, 'before' => $before, 'after' => $after, 'gateway' => $result];
    }

    /** Cashfree webhook: verify signature, then settle idempotently. */
    public static function handleWebhook(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            throw new ApiError('invalid_body', 'Empty webhook', 400);
        }
        $sig = (string) ($_SERVER['HTTP_X_CASHFREE_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
        $ts  = (string) ($_SERVER['HTTP_X_CASHFREE_TIMESTAMP'] ?? '');
        if (!self::verifyWebhookSignature($raw, $sig, $ts)) {
            throw new ApiError('invalid_signature', 'Webhook signature verification failed', 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new ApiError('invalid_body', 'Invalid webhook payload', 400);
        }
        $data = $payload['data'] ?? $payload;
        $order = $data['order'] ?? [];
        $payment = $data['payment'] ?? [];
        $cfOrderId = (string) ($order['cf_order_id'] ?? $payment['cf_order_id'] ?? '');
        $payStatus = strtoupper((string) ($payment['payment_status'] ?? $order['order_status'] ?? ''));
        if ($cfOrderId === '') {
            throw new ApiError('invalid_body', 'Webhook missing order id', 400);
        }
        // Idempotency/audit log for this delivery (spec: webhook_events table).
        try {
            Db::run(
                'INSERT IGNORE INTO webhook_events (event_id, source, payload_hash, status, processed_at)
                 VALUES (?,?,?,?,NOW())',
                [
                    hash('sha256', $raw),
                    'cashfree',
                    hash('sha256', $raw),
                    'received',
                ]
            );
        } catch (\Throwable $e) {
            error_log('[ExamLegacy] webhook_events log failed: ' . $e->getMessage());
        }
        $intent = Db::one('SELECT * FROM payment_intents WHERE provider_order_id = ?', [$cfOrderId]);
        if ($intent === null) {
            return ['ok' => true, 'ignored' => true];
        }
        if ($payStatus === 'SUCCESS' || $payStatus === 'PAID') {
            self::settle($intent, $order + $payment);
        } elseif ($payStatus === 'FAILED') {
            Db::run('UPDATE payment_intents SET status = \'failed\' WHERE id = ? AND status = \'pending\'', [(int) $intent['id']]);
            if ((int) $intent['ref_id'] > 0 && $intent['kind'] === 'order') {
                Orders::markFailed((int) $intent['ref_id'], 'gateway_failed');
            }
        }
        return ['ok' => true];
    }

    /**
     * Settle an intent after confirmed gateway success. Idempotent. Dispatches
     * the correct business effect by kind.
     */
    private static function settle(array $intent, array $gatewayData): void
    {
        if ($intent['status'] === 'paid') {
            return;
        }
        // Amount sanity check (gateway rupees -> paise) when available.
        if (isset($gatewayData['order_amount'])) {
            $gatewayAmount = (int) round(((float) $gatewayData['order_amount']) * 100);
            if ($gatewayAmount > 0 && $gatewayAmount !== (int) $intent['amount_paise']) {
                error_log('[ExamLegacy] Intent amount mismatch id=' . $intent['id']);
                return; // do not settle on mismatch
            }
        }
        Db::transaction(function () use ($intent, $gatewayData) {
            // Lock the intent row to prevent double settle under concurrency.
            $fresh = Db::one('SELECT * FROM payment_intents WHERE id = ? FOR UPDATE', [(int) $intent['id']]);
            if ($fresh === null || $fresh['status'] === 'paid') {
                return;
            }
            Db::run('UPDATE payment_intents SET status = \'paid\', paid_at = NOW() WHERE id = ?', [(int) $intent['id']]);
            $kind = (string) $fresh['kind'];
            $userId = (int) $fresh['user_id'];
            $providerOrderId = (string) $fresh['provider_order_id'];
            $meta = json_decode((string) ($fresh['meta'] ?? '{}'), true) ?: [];

            switch ($kind) {
                case 'order':
                    Orders::markPaid((int) $fresh['ref_id'], 'gateway');
                    $order = Db::one('SELECT order_code, total_paise FROM orders WHERE id = ?', [(int) $fresh['ref_id']]);
                    if ($order) {
                        $u = Db::one('SELECT email, name FROM users WHERE id = ?', [$userId]);
                        if ($u) {
                            Mailer::sendPurchaseReceipt((string) $u['email'], (string) $u['name'], Orders::publicOrder((int) $fresh['ref_id']));
                        }
                    }
                    break;
                case 'recharge':
                    Wallet::credit($userId, (int) $fresh['amount_paise'], 'recharge', 'recharge:' . $providerOrderId,
                        'Wallet recharge', null, null, $providerOrderId);
                    Notifications::emit($userId, 'payment', 'Wallet recharged',
                        'Your store wallet was topped up by ₹' . money((int) $fresh['amount_paise']) . '.',
                        ['amount_paise' => (int) $fresh['amount_paise']], 'recharge:' . $providerOrderId);
                    break;
                case 'credit_pack':
                    $credits = (int) ($meta['credits'] ?? 0) + (int) ($meta['bonus_credits'] ?? 0);
                    if ($credits > 0) {
                        AiCredits::credit($userId, $credits, 'purchase', 'creditpack:' . $providerOrderId,
                            'AI credit pack: ' . (string) ($meta['name'] ?? 'pack'), null, $providerOrderId);
                    }
                    Notifications::emit($userId, 'system', 'AI Credits added',
                        $credits . ' AI credits were added to your account.',
                        ['credits' => $credits], 'creditpack:' . $providerOrderId);
                    break;
                case 'vip':
                    self::activateVip($userId, (int) ($meta['plan_id'] ?? 0), (string) ($meta['plan_name'] ?? 'VIP PASS'));
                    break;
            }
        });
    }

    private static function activateVip(int $userId, int $planId, string $planName): void
    {
        if ($planId <= 0) {
            return;
        }
        $plan = Db::one('SELECT * FROM vip_plans WHERE id = ?', [$planId]);
        if ($plan === null) {
            return;
        }
        $expires = null;
        if ($plan['interval'] === 'monthly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 month'));
        } elseif ($plan['interval'] === 'yearly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 year'));
        }
        Db::run('UPDATE vip_subscriptions SET status = \'expired\' WHERE user_id = ? AND status = \'active\'', [$userId]);
        Db::run('INSERT INTO vip_subscriptions (user_id, plan_id, status, expires_at) VALUES (?,?,?,?)',
            [$userId, $planId, 'active', $expires]);
        Db::run('UPDATE users SET vip_active = 1, vip_expires_at = ? WHERE id = ?', [$expires, $userId]);
        Notifications::emit($userId, 'system', 'VIP PASS activated',
            'Your VIP PASS (' . $planName . ') is now active.', ['plan' => (string) $plan['code']],
            'vip_activate:' . $userId . ':' . $planId . ':' . date('Ymd'));
        // Real-time secondary mirror (spec §2.2) — non-fatal.
        Firestore::doc('vip_subscriptions', $userId . '_' . $planId, [
            'user_id'    => $userId,
            'plan_id'    => $planId,
            'plan_code'  => (string) $plan['code'],
            'plan_name'  => $planName,
            'status'     => 'active',
            'expires_at' => $expires,
            'mirrored_at'=> gmdate('c'),
        ]);
    }

    private static function verifyWebhookSignature(string $raw, string $sig, string $ts): bool
    {
        if ($sig === '' || CASHFREE_WEBHOOK_SECRET === '') {
            return false;
        }
        $signed = $ts !== '' ? ($ts . $raw) : $raw;
        return hash_equals(hash_hmac('sha256', $signed, CASHFREE_WEBHOOK_SECRET), $sig);
    }
}
