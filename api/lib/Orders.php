<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Orders — purchase engine.
 *
 * - Orders are created PENDING. They become PAID only after server-side
 *   confirmation (wallet debit succeeds, or a verified gateway payment).
 * - The wallet portion is debited atomically at PAY time (not at creation),
 *   idempotently, so a failed gateway payment never captures funds.
 * - On PAY we grant PDF ownership access and emit idempotent notifications.
 */
final class Orders
{
    /** Active VIP member discount percentage (0 if none). */
    public static function memberDiscountPct(int $userId): int
    {
        $row = Db::one(
            "SELECT p.benefits FROM vip_subscriptions m
             JOIN vip_plans p ON p.id = m.plan_id
             WHERE m.user_id = ? AND m.status = 'active'
               AND (m.expires_at IS NULL OR m.expires_at > NOW())
             ORDER BY m.id DESC LIMIT 1",
            [$userId]
        );
        if ($row === null || empty($row['benefits'])) {
            return 0;
        }
        $b = json_decode((string) $row['benefits'], true);
        return (int) ($b['member_discount_pct'] ?? 0);
    }

    public static function isVip(int $userId): bool
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM vip_subscriptions WHERE user_id = ? AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW())",
            [$userId]
        ) > 0;
    }

    /**
     * Create an order from a list of product ids.
     * Returns ['order' => publicOrder, 'payment' => null|cashfreeSession].
     */
    public static function create(int $userId, array $productIds, string $method, int $walletRequestPaise = 0): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (empty($productIds)) {
            throw new ApiError('empty_cart', 'No items to purchase', 400);
        }
        if (!in_array($method, ['wallet', 'cashfree', 'split'], true)) {
            throw new ApiError('invalid_method', 'Invalid payment method', 400);
        }

        // Load & validate products.
        $products = Db::all(
            'SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ') AND is_published = 1',
            $productIds
        );
        if (count($products) !== count($productIds)) {
            throw new ApiError('invalid_product', 'One or more products are unavailable', 400);
        }
        $byId = [];
        foreach ($products as $p) {
            $byId[(int) $p['id']] = $p;
        }

        $discountPct = self::memberDiscountPct($userId);
        $subtotal = 0;
        $items = [];
        foreach ($productIds as $pid) {
            $p = $byId[$pid];
            $price = (int) $p['price_paise'];
            if ($discountPct > 0) {
                $price = (int) round($price * (100 - $discountPct) / 100);
            }
            $subtotal += $price;
            $items[] = ['product_id' => $pid, 'price_paise' => $price, 'title_snapshot' => (string) $p['title']];
        }
        $total = $subtotal;

        // Determine wallet vs gateway split.
        $walletBalance = Wallet::balance($userId);
        $walletApplied = 0;
        if ($method === 'wallet') {
            if ($walletBalance < $total) {
                throw new ApiError('insufficient_balance', 'Insufficient wallet balance', 400);
            }
            $walletApplied = $total;
        } elseif ($method === 'split') {
            $walletApplied = max(0, min($walletRequestPaise, $walletBalance, $total));
            if ($total - $walletApplied <= 0) {
                throw new ApiError('invalid_split', 'Split must leave a gateway amount', 400);
            }
        }
        $gatewayAmount = $total - $walletApplied;

        // If a gateway payment is needed, the customer phone is required by Cashfree.
        // Validate BEFORE creating the order so failed attempts don't orphan pending orders.
        if ($gatewayAmount > 0) {
            $phone = Db::scalar('SELECT phone FROM users WHERE id = ?', [$userId]);
            if ($phone === null || $phone === '') {
                throw new ApiError('phone_required', 'Please add a phone number to your account before online payment', 422);
            }
        }

        // Persist order (PENDING).
        $orderCode = public_code('EL');
        Db::run(
            'INSERT INTO orders
                (order_code, user_id, status, payment_method, subtotal_paise, wallet_applied_paise, gateway_amount_paise, total_paise, currency)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$orderCode, $userId, 'pending', $method, $subtotal, $walletApplied, $gatewayAmount, $total, 'INR']
        );
        $orderId = Db::insertId();
        foreach ($items as $it) {
            Db::run(
                'INSERT INTO order_items (order_id, product_id, title_snapshot, price_paise) VALUES (?,?,?,?)',
                [$orderId, $it['product_id'], $it['title_snapshot'], $it['price_paise']]
            );
        }

        $payment = null;
        if ($gatewayAmount > 0) {
            // Create a Cashfree order server-side; return its session to the client.
            $payment = Payments::createIntent($userId, 'order', $gatewayAmount, $orderId, [
                'order_code' => $orderCode,
            ], 'order:' . $orderId);
            Db::run('UPDATE orders SET provider = ?, provider_order_id = ? WHERE id = ?', [
                'cashfree', $payment['provider_order_id'], $orderId,
            ]);
        }

        // Wallet-only: confirm immediately (atomic debit + grant).
        if ($gatewayAmount === 0) {
            self::markPaid($orderId, 'wallet');
        }

        return [
            'order'   => self::publicOrder($orderId, $userId),
            'payment' => $payment,
        ];
    }

    /**
     * Mark an order PAID. Idempotent & atomic. Debits the wallet portion,
     * grants PDF access, emits notifications. Source = 'wallet'|'gateway'|'verify'.
     */
    public static function markPaid(int $orderId, string $source = 'verify'): array
    {
        $result = Db::transaction(function () use ($orderId, $source) {
            $order = Db::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($order === null) {
                throw new ApiError('not_found', 'Order not found', 404);
            }
            if ($order['status'] === 'paid') {
                return ['already_paid' => true, 'order_id' => $orderId];
            }
            if ($order['status'] !== 'pending') {
                throw new ApiError('invalid_state', 'Order is not payable', 409);
            }
            $userId = (int) $order['user_id'];

            // Debit the wallet portion idempotently (never for pure-gateway where applied=0).
            if ((int) $order['wallet_applied_paise'] > 0) {
                Wallet::debit(
                    $userId,
                    (int) $order['wallet_applied_paise'],
                    'purchase',
                    'order_wallet:' . $orderId,
                    'Purchase ' . (string) $order['order_code'],
                    $orderId
                );
            }

            Db::run('UPDATE orders SET status = \'paid\', paid_at = NOW() WHERE id = ?', [$orderId]);

            // Grant access for each purchased product.
            $items = Db::all('SELECT product_id FROM order_items WHERE order_id = ?', [$orderId]);
            foreach ($items as $it) {
                PdfVault::grant($userId, (int) $it['product_id'], $orderId);
            }

            // Idempotent notifications.
            Notifications::emit($userId, 'purchase', 'Purchase successful',
                'Your order ' . (string) $order['order_code'] . ' is confirmed. Access granted.',
                ['order_id' => $orderId, 'order_code' => (string) $order['order_code']],
                'order_paid:' . $orderId);
            Notifications::emit($userId, 'order', 'Order confirmed',
                'Order ' . (string) $order['order_code'] . ' has been paid.',
                ['order_id' => $orderId],
                'order_confirmed:' . $orderId);
            foreach ($items as $it) {
                Notifications::emit($userId, 'pdf', 'PDF access granted',
                    'You now have access to a new document in your library.',
                    ['product_id' => (int) $it['product_id'], 'order_id' => $orderId],
                    'pdf_granted:' . $orderId . ':' . (int) $it['product_id']);
            }

            return ['already_paid' => false, 'order_id' => $orderId, 'source' => $source];
        });

        // Real-time secondary mirror (spec §2.2) — non-fatal; MySQL stays master.
        $o = Db::one('SELECT order_code, user_id, status, total_paise, paid_at FROM orders WHERE id = ?', [$orderId]);
        if ($o !== null) {
            Firestore::doc('orders', (string) $orderId, [
                'order_code'   => (string) $o['order_code'],
                'user_id'      => (int) $o['user_id'],
                'status'       => (string) $o['status'],
                'total_paise'  => (int) $o['total_paise'],
                'paid_at'      => $o['paid_at'] !== null ? (string) $o['paid_at'] : null,
                'mirrored_at'  => gmdate('c'),
            ]);
        }
        return $result;
    }

    public static function markFailed(int $orderId, string $reason): void
    {
        Db::run('UPDATE orders SET status = \'failed\', failure_reason = ? WHERE id = ? AND status = \'pending\'', [
            substr($reason, 0, 255), $orderId,
        ]);
        $order = Db::one('SELECT user_id, order_code FROM orders WHERE id = ?', [$orderId]);
        if ($order) {
            Notifications::emit((int) $order['user_id'], 'payment', 'Payment failed',
                'Payment for order ' . (string) $order['order_code'] . ' did not complete.',
                ['order_id' => $orderId, 'reason' => $reason],
                'order_failed:' . $orderId);
        }
    }

    /** Admin refund: credit wallet, revoke access, mark refunded. Idempotent. */
    public static function refund(int $orderId, ?int $adminId = null): void
    {
        Db::transaction(function () use ($orderId, $adminId) {
            $order = Db::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($order === null || $order['status'] !== 'paid') {
                throw new ApiError('invalid_state', 'Only paid orders can be refunded', 409);
            }
            $userId = (int) $order['user_id'];
            // Credit back the total (wallet portion + any gateway portion as store credit).
            Wallet::credit($userId, (int) $order['total_paise'], 'refund', 'refund:' . $orderId,
                'Refund for order ' . (string) $order['order_code'], $orderId, null, null, $adminId);
            // Revoke access.
            $items = Db::all('SELECT product_id FROM order_items WHERE order_id = ?', [$orderId]);
            foreach ($items as $it) {
                PdfVault::revoke($userId, (int) $it['product_id'], 'refunded');
            }
            Db::run('UPDATE orders SET status = \'refunded\' WHERE id = ?', [$orderId]);
            Notifications::emit($userId, 'payment', 'Refund processed',
                'Your order ' . (string) $order['order_code'] . ' was refunded to your store wallet.',
                ['order_id' => $orderId],
                'order_refund:' . $orderId);
        });
    }

    public static function publicOrder(int $orderId, ?int $scopeUserId = null): ?array
    {
        $order = Db::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            return null;
        }
        if ($scopeUserId !== null && (int) $order['user_id'] !== $scopeUserId) {
            throw new ApiError('forbidden', 'Not your order', 403);
        }
        $items = Db::all(
            'SELECT oi.product_id, oi.price_paise, oi.title_snapshot, p.thumbnail_path, p.slug
             FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?',
            [$orderId]
        );
        return [
            'id' => (int) $order['id'],
            'order_code' => (string) $order['order_code'],
            'status' => (string) $order['status'],
            'payment_method' => (string) $order['payment_method'],
            'subtotal_paise' => (int) $order['subtotal_paise'],
            'wallet_applied_paise' => (int) $order['wallet_applied_paise'],
            'gateway_amount_paise' => (int) $order['gateway_amount_paise'],
            'total_paise' => (int) $order['total_paise'],
            'currency' => (string) $order['currency'],
            'created_at' => (string) $order['created_at'],
            'paid_at' => $order['paid_at'] !== null ? (string) $order['paid_at'] : null,
            'items' => array_map(fn($it) => [
                'product_id' => (int) $it['product_id'],
                'title' => (string) $it['title_snapshot'],
                'price_paise' => (int) $it['price_paise'],
                'slug' => (string) ($it['slug'] ?? ''),
                'thumbnail_url' => Store::coverUrl($it['thumbnail_path'] ?? null),
            ], $items),
        ];
    }

    public static function listForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $orders = Db::all(
            'SELECT id FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            [$userId]
        );
        return array_map(fn($o) => self::publicOrder((int) $o['id'], $userId), $orders);
    }
}
