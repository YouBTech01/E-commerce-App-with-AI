<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Admin — business management control surface.
 *
 * EXTENDS the existing required capabilities (PDF grant/revoke, wallet manual
 * credit/debit, AI credit add/deduct, user block/unblock & enable/disable,
 * manual notifications, settings, audit history, financial reference ids).
 * Every privileged action is written to the immutable audit log.
 */
final class Admin
{
    public static function audit(int $actorId, string $action, string $entity, $entityId, $before = null, $after = null): void
    {
        Db::run(
            'INSERT INTO admin_audit_logs (actor_user_id, actor_role, action, entity, entity_id, before_json, after_json, ip)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $actorId, 'admin', $action, $entity,
                $entityId !== null ? (string) $entityId : null,
                $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                ip_to_binary(client_ip()),
            ]
        );
    }

    public static function listUsers(?string $search = null, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if ($search !== null && $search !== '') {
            $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT id, firebase_uid, name, email, phone, role, status, wallet_balance_paise, ai_credit_balance,
                       vip_active, created_at, last_login_at
                FROM users';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);
        return Db::all($sql, $params);
    }

    public static function getUser(int $userId): array
    {
        $user = Db::one('SELECT id, firebase_uid, name, email, phone, role, status, wallet_balance_paise,
                                ai_credit_balance, vip_active, vip_expires_at, created_at, last_login_at
                         FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw new ApiError('not_found', 'User not found', 404);
        }
        $user['orders'] = Db::all('SELECT id, order_code, status, total_paise, created_at, paid_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId]);
        $user['pdf_access'] = Db::all(
            'SELECT a.id, a.status, a.granted_at, a.expires_at, a.revoked_reason, p.title
             FROM pdf_access a JOIN products p ON p.id = a.product_id WHERE a.user_id = ? ORDER BY a.id DESC', [$userId]);
        return $user;
    }

    public static function setUserStatus(int $actorId, int $userId, string $status, string $reason = ''): void
    {
        if (!in_array($status, ['active', 'suspended', 'disabled'], true)) {
            throw new ApiError('invalid_status', 'Invalid status', 400);
        }
        $before = Db::one('SELECT status FROM users WHERE id = ?', [$userId]);
        if ($before === null) {
            throw new ApiError('not_found', 'User not found', 404);
        }
        Db::run('UPDATE users SET status = ? WHERE id = ?', [$status, $userId]);
        self::audit($actorId, 'user_status', 'user', $userId, $before, ['status' => $status, 'reason' => $reason]);
        Notifications::emit($userId, 'account', 'Account status updated',
            'Your account status is now: ' . $status . ($reason ? ' (' . $reason . ')' : ''),
            ['status' => $status], 'user_status:' . $userId . ':' . $status);
    }

    public static function adjustWallet(int $actorId, int $userId, int $amountSigned, string $reason): int
    {
        $ref = 'admin_wallet:' . $userId . ':' . random_hex(6);
        if ($amountSigned >= 0) {
            $balance = Wallet::credit($userId, $amountSigned, 'adjustment', $ref, $reason, null, null, null, $actorId);
        } else {
            $balance = Wallet::debit($userId, -$amountSigned, 'adjustment', $ref, $reason, null, $actorId);
        }
        self::audit($actorId, 'wallet_adjust', 'user', $userId, null, ['amount_paise' => $amountSigned, 'reason' => $reason, 'reference' => $ref]);
        Notifications::emit($userId, 'payment', 'Wallet updated',
            ($amountSigned >= 0 ? 'Credited' : 'Debited') . ' ' . money(abs($amountSigned)) . ' to your store wallet.',
            ['amount_paise' => $amountSigned, 'reference' => $ref], 'wallet_adjust:' . $ref);
        return $balance;
    }

    public static function adjustCredits(int $actorId, int $userId, int $amountSigned, string $reason): int
    {
        $ref = 'admin_credits:' . $userId . ':' . random_hex(6);
        if ($amountSigned >= 0) {
            $balance = AiCredits::credit($userId, $amountSigned, 'adjustment', $ref, $reason, null, null, $actorId);
        } else {
            $balance = AiCredits::debit($userId, -$amountSigned, $ref, $reason, $actorId);
        }
        self::audit($actorId, 'credits_adjust', 'user', $userId, null, ['amount' => $amountSigned, 'reason' => $reason, 'reference' => $ref]);
        Notifications::emit($userId, 'system', 'AI Credits updated',
            ($amountSigned >= 0 ? 'Added' : 'Deducted') . ' ' . abs($amountSigned) . ' AI credits.',
            ['amount' => $amountSigned, 'reference' => $ref], 'credits_adjust:' . $ref);
        return $balance;
    }

    public static function grantPdf(int $actorId, int $userId, int $productId): void
    {
        PdfVault::grant($userId, $productId, null);
        self::audit($actorId, 'pdf_grant', 'pdf_access', null, null, ['user_id' => $userId, 'product_id' => $productId]);
        Notifications::emit($userId, 'pdf', 'PDF access granted',
            'An administrator granted you access to a document.', ['product_id' => $productId],
            'pdf_admin_grant:' . $userId . ':' . $productId);
    }

    public static function revokePdf(int $actorId, int $userId, int $productId): void
    {
        PdfVault::revoke($userId, $productId, 'admin_revoked');
        self::audit($actorId, 'pdf_revoke', 'pdf_access', null, null, ['user_id' => $userId, 'product_id' => $productId]);
        Notifications::emit($userId, 'pdf', 'PDF access revoked',
            'Your access to a document was revoked.', ['product_id' => $productId],
            'pdf_admin_revoke:' . $userId . ':' . $productId);
    }

    public static function notifyUser(int $actorId, int $userId, string $category, string $title, string $body): void
    {
        Notifications::emit($userId, $category, $title, $body, [], 'admin_notify:' . $userId . ':' . random_hex(6));
        self::audit($actorId, 'notify_user', 'user', $userId, null, ['category' => $category, 'title' => $title]);
    }

    public static function broadcast(int $actorId, string $category, string $title, string $body): void
    {
        Notifications::broadcast($category, $title, $body, [], 'admin_broadcast:' . random_hex(8));
        self::audit($actorId, 'broadcast', 'notification', null, null, ['category' => $category, 'title' => $title]);
    }

    public static function stats(): array
    {
        return [
            'users_total' => (int) Db::scalar('SELECT COUNT(*) FROM users'),
            'users_active' => (int) Db::scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'orders_total' => (int) Db::scalar('SELECT COUNT(*) FROM orders'),
            'orders_paid' => (int) Db::scalar("SELECT COUNT(*) FROM orders WHERE status = 'paid'"),
            'orders_pending' => (int) Db::scalar("SELECT COUNT(*) FROM orders WHERE status = 'pending'"),
            'revenue_paise' => (int) Db::scalar("SELECT COALESCE(SUM(total_paise),0) FROM orders WHERE status = 'paid'"),
            'products_published' => (int) Db::scalar('SELECT COUNT(*) FROM products WHERE is_published = 1'),
            'pdf_access_active' => (int) Db::scalar("SELECT COUNT(*) FROM pdf_access WHERE status = 'active'"),
            'support_open' => (int) Db::scalar("SELECT COUNT(*) FROM support_threads WHERE status IN ('open','waiting_admin')"),
            'vip_active' => (int) Db::scalar("SELECT COUNT(*) FROM users WHERE vip_active = 1"),
        ];
    }

    public static function listOrders(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        if ($status !== null && $status !== '' && $status !== 'all') {
            $rows = Db::all('SELECT id FROM orders WHERE status = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset), [$status]);
        } else {
            $rows = Db::all('SELECT id FROM orders ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset));
        }
        return array_map(fn($r) => Orders::publicOrder((int) $r['id']), $rows);
    }

    public static function auditLog(int $limit = 100, int $offset = 0): array
    {
        return Db::all(
            'SELECT a.id, a.action, a.entity, a.entity_id, a.before_json, a.after_json, a.created_at, u.name AS actor_name, u.email AS actor_email
             FROM admin_audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.id DESC LIMIT ' . max(1, min(300, $limit)) . ' OFFSET ' . max(0, $offset)
        );
    }

    public static function allSettings(): array
    {
        $out = [];
        foreach (Db::all('SELECT `key`, value FROM site_settings ORDER BY `key`') as $r) {
            $out[(string) $r['key']] = (string) ($r['value'] ?? '');
        }
        return $out;
    }

    public static function saveSettings(int $actorId, array $settings): void
    {
        foreach ($settings as $k => $v) {
            if (!is_string($k) || !preg_match('/^[a-z0-9_]+$/', $k)) {
                continue;
            }
            Settings::set($k, is_scalar($v) ? (string) $v : null);
        }
        self::audit($actorId, 'settings_update', 'settings', null, null, array_keys($settings));
    }

    public static function vipPlans(): array
    {
        return Db::all('SELECT * FROM vip_plans WHERE is_active = 1 ORDER BY price_paise');
    }

    public static function grantVip(int $actorId, int $userId, int $planId): void
    {
        $plan = Db::one('SELECT * FROM vip_plans WHERE id = ?', [$planId]);
        if ($plan === null) {
            throw new ApiError('not_found', 'VIP plan not found', 404);
        }
        $expires = null;
        if ($plan['interval'] === 'monthly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 month'));
        } elseif ($plan['interval'] === 'yearly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 year'));
        } // unlimited => NULL (no expiry)
        Db::run('UPDATE vip_subscriptions SET status = \'expired\' WHERE user_id = ? AND status = \'active\'', [$userId]);
        Db::run('INSERT INTO vip_subscriptions (user_id, plan_id, status, expires_at) VALUES (?,?,?,?)',
            [$userId, $planId, 'active', $expires]);
        Db::run('UPDATE users SET vip_active = 1, vip_expires_at = ? WHERE id = ?', [$expires, $userId]);
        self::audit($actorId, 'vip_grant', 'vip', null, null, ['user_id' => $userId, 'plan' => $plan['code']]);
        Notifications::emit($userId, 'system', 'VIP PASS activated',
            'Your VIP PASS (' . (string) $plan['name'] . ') is now active.', ['plan' => $plan['code']],
            'vip_grant:' . $userId . ':' . $planId);
    }
}
