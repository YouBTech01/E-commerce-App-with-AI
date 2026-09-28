<?php
declare(strict_types=1);

/**
 * ExamLegacy API front controller.
 * All business logic lives in api/lib/*; this file only routes + authorizes.
 * The frontend is NEVER a security authority: every protected route verifies a
 * Firebase ID token (Bearer) or a short-lived viewer session token.
 */
require __DIR__ . '/config.php';

use ExamLegacy\ApiError;
use ExamLegacy\Auth;
use ExamLegacy\Http;
use ExamLegacy\Db;
use ExamLegacy\Store;
use ExamLegacy\Orders;
use ExamLegacy\Payments;
use ExamLegacy\Wallet;
use ExamLegacy\AiCredits;
use ExamLegacy\PdfVault;
use ExamLegacy\StudyAi;
use ExamLegacy\Support;
use ExamLegacy\Notifications;
use ExamLegacy\Settings;
use ExamLegacy\Admin;
use ExamLegacy\Mailer;

// ---- CORS (same-origin by default; the frontend is served from this origin) --

$method = Http::method();
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Viewer-Token');
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Strip a base-path prefix if the API is mounted under /api.
if (strpos($path, '/api') === 0) {
    $path = substr($path, 4);
}
$path = '/' . trim($path, '/');

try {
    route($path, $method);
} catch (ApiError $e) {
    Http::fail($e);
} catch (Throwable $e) {
    error_log('[ExamLegacy] ' . $e->getMessage());
    Http::fail(new ApiError('server_error', APP_DEBUG ? $e->getMessage() : 'Something went wrong', 500));
}

// ===========================================================================
function route(string $path, string $method): void
{
    // ---- Public routes ----
    if ($path === '/config' && $method === 'GET') {
        Http::ok(['config' => Settings::publicConfig()]);
        return;
    }
    // Uptime/health probe — exposes no internals.
    if ($path === '/health' && $method === 'GET') {
        try {
            Db::one('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new ApiError('db_unavailable', 'Service temporarily unavailable', 503);
        }
        Http::ok(['status' => 'ok', 'db' => true, 'version' => '2.0.0', 'time' => gmdate('c')]);
        return;
    }
    // Superadmin console login — dedicated email+password (zero Google dependency).
    if ($path === '/admin/auth/login' && $method === 'POST') {
        $in = Http::jsonBody();
        Http::ok(Auth::adminLogin((string) ($in['email'] ?? ''), (string) ($in['password'] ?? '')));
        return;
    }
    if ($path === '/categories' && $method === 'GET') {
        Http::ok(['categories' => Store::categories()]);
        return;
    }
    if ($path === '/products' && $method === 'GET') {
        $userId = optionalUserId();
        $items = Store::listPublished(
            Http::query('search'), Http::query('category'),
            (int) (Http::query('limit') ?? '60'), (int) (Http::query('offset') ?? '0'), $userId
        );
        Http::ok(['products' => $items]);
        return;
    }
    if (preg_match('#^/products/([A-Za-z0-9\-]+)$#', $path, $m) && $method === 'GET') {
        $userId = optionalUserId();
        $p = Store::findPublishedBySlug($m[1], $userId);
        if ($p === null) {
            throw new ApiError('not_found', 'Product not found', 404);
        }
        Http::ok(['product' => $p]);
        return;
    }
    if ($path === '/vip/plans' && $method === 'GET') {
        Http::ok(['plans' => array_map(fn($p) => [
            'id' => (int) $p['id'], 'code' => (string) $p['code'], 'name' => (string) $p['name'],
            'interval' => (string) $p['interval'], 'price_paise' => (int) $p['price_paise'],
            'currency' => (string) $p['currency'], 'benefits' => json_decode((string) ($p['benefits'] ?? '{}'), true),
            'fair_use' => json_decode((string) ($p['fair_use'] ?? '{}'), true),
        ], Db::all('SELECT * FROM vip_plans WHERE is_active = 1 ORDER BY price_paise'))]);
        return;
    }
    if ($path === '/credits/packs' && $method === 'GET') {
        Http::ok(['packs' => array_map(fn($p) => [
            'id' => (int) $p['id'], 'code' => (string) $p['code'], 'name' => (string) $p['name'],
            'credits' => (int) $p['credits'], 'bonus_credits' => (int) $p['bonus_credits'],
            'price_paise' => (int) $p['price_paise'], 'currency' => (string) $p['currency'],
        ], Db::all('SELECT * FROM credit_packs WHERE is_active = 1 ORDER BY price_paise'))]);
        return;
    }
    if ($path === '/cover' && $method === 'GET') {
        streamCover(Http::query('f') ?? '');
        return;
    }
    if ($path === '/payments/webhook' && $method === 'POST') {
        Http::ok(Payments::handleWebhook());
        return;
    }

    // ---- Viewer (short-lived session token, not bearer) ----
    if ($path === '/viewer/stream' && $method === 'GET') {
        $token = $_SERVER['HTTP_X_VIEWER_TOKEN'] ?? (Http::query('token') ?? '');
        if (!is_string($token) || $token === '') {
            throw new ApiError('unauthenticated', 'Missing viewer token', 401);
        }
        PdfVault::stream($token); // streams & exits
        return;
    }

    // ---- Everything below requires an authenticated user ----
    if ($path === '/me' && $method === 'GET') {
        $u = Auth::authenticate();
        Http::ok(['user' => userShape($u)]);
        return;
    }
    if ($path === '/me' && ($method === 'POST' || $method === 'PATCH')) {
        $u = Auth::authenticate();
        $b = Http::jsonBody();
        $fields = [];
        $params = [];
        if (isset($b['name']) && is_string($b['name']) && trim($b['name']) !== '') {
            $fields[] = 'name = ?';
            $params[] = trim(mb_substr($b['name'], 0, 190));
        }
        if (isset($b['phone'])) {
            $phone = preg_replace('/[^0-9+]/', '', (string) $b['phone']);
            $fields[] = 'phone = ?';
            $params[] = $phone !== '' ? substr($phone, 0, 24) : null;
        }
        if ($fields) {
            $params[] = (int) $u['id'];
            Db::run('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        }
        $u = Db::one('SELECT * FROM users WHERE id = ?', [(int) $u['id']]);
        Http::ok(['user' => userShape($u)]);
        return;
    }
    // Profile picture (DP) upload — multipart file OR JSON base64 data URL.
    if ($path === '/me/avatar' && $method === 'POST') {
        $u = Auth::authenticate();
        Http::ok(['user' => handleAvatarUpload((int) $u['id'])]);
        return;
    }

    if ($path === '/library' && $method === 'GET') {
        $u = Auth::authenticate();
        $rows = Db::all(
            "SELECT p.id, p.slug, p.title, p.subtitle, p.category, p.page_count, p.file_size_bytes,
                    p.thumbnail_path, p.download_allowed, a.granted_at, a.expires_at
             FROM pdf_access a JOIN products p ON p.id = a.product_id
             WHERE a.user_id = ? AND a.status = 'active' AND (a.expires_at IS NULL OR a.expires_at > NOW())
             ORDER BY a.id DESC",
            [(int) $u['id']]
        );
        Http::ok(['items' => array_map(fn($r) => [
            'product_id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'title' => (string) $r['title'],
            'subtitle' => (string) $r['subtitle'],
            'category' => (string) $r['category'],
            'page_count' => (int) $r['page_count'],
            'file_size_bytes' => (int) $r['file_size_bytes'],
            'thumbnail_url' => Store::coverUrl($r['thumbnail_path'] ?? null),
            'download_allowed' => (bool) $r['download_allowed'],
            'granted_at' => (string) $r['granted_at'],
            'expires_at' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null,
        ], $rows)]);
        return;
    }

    // ---- Orders ----
    if ($path === '/orders' && $method === 'GET') {
        $u = Auth::authenticate();
        Http::ok(['orders' => Orders::listForUser((int) $u['id'])]);
        return;
    }
    if ($path === '/orders' && $method === 'POST') {
        $u = Auth::authenticate();
        RateLimit::forUser('orders', (int) $u['id'], 15, 60);
        $b = Http::jsonBody();
        $ids = isset($b['product_ids']) && is_array($b['product_ids']) ? $b['product_ids'] : [];
        $methodName = (string) ($b['method'] ?? 'cashfree');
        $walletPaise = (int) ($b['wallet_paise'] ?? 0);
        $result = Orders::create((int) $u['id'], $ids, $methodName, $walletPaise);
        Http::ok($result, 201);
        return;
    }
    if (preg_match('#^/orders/([A-Z0-9]+)$#', $path, $m) && $method === 'GET') {
        $u = Auth::authenticate();
        $order = Db::one('SELECT id FROM orders WHERE order_code = ?', [$m[1]]);
        if ($order === null) {
            throw new ApiError('not_found', 'Order not found', 404);
        }
        Http::ok(['order' => Orders::publicOrder((int) $order['id'], (int) $u['id'])]);
        return;
    }

    // ---- Payments verify (browser callback is NOT authority; we re-check) ----
    if ($path === '/payments/verify' && $method === 'POST') {
        $u = Auth::authenticate();
        $b = Http::jsonBody();
        $intentId = (int) ($b['intent_id'] ?? 0);
        Http::ok(Payments::verifyIntent((int) $u['id'], $intentId));
        return;
    }

    // ---- Wallet ----
    if ($path === '/wallet' && $method === 'GET') {
        $u = Auth::authenticate();
        Http::ok([
            'balance_paise' => Wallet::balance((int) $u['id']),
            'currency' => 'INR',
            'history' => Wallet::history((int) $u['id']),
        ]);
        return;
    }
    if ($path === '/wallet/recharge' && $method === 'POST') {
        $u = Auth::authenticate();
        RateLimit::forUser('recharge', (int) $u['id'], 15, 60);
        $b = Http::jsonBody();
        $amount = (int) ($b['amount_paise'] ?? 0);
        if ($amount < 1000) {
            throw new ApiError('invalid_amount', 'Minimum recharge is ₹10', 400);
        }
        if ($amount > 500000) {
            throw new ApiError('invalid_amount', 'Maximum recharge is ₹5000', 400);
        }
        $intent = Payments::createIntent((int) $u['id'], 'recharge', $amount, null, [], 'recharge:' . (int) $u['id'] . ':' . random_hex(6));
        Http::ok($intent, 201);
        return;
    }

    // ---- AI Credits ----
    if ($path === '/credits' && $method === 'GET') {
        $u = Auth::authenticate();
        Http::ok(['balance' => AiCredits::balance((int) $u['id']), 'history' => AiCredits::history((int) $u['id'])]);
        return;
    }
    if ($path === '/credits/purchase' && $method === 'POST') {
        $u = Auth::authenticate();
        $b = Http::jsonBody();
        $packId = (int) ($b['pack_id'] ?? 0);
        $pack = Db::one('SELECT * FROM credit_packs WHERE id = ? AND is_active = 1', [$packId]);
        if ($pack === null) {
            throw new ApiError('not_found', 'Credit pack not found', 404);
        }
        $intent = Payments::createIntent((int) $u['id'], 'credit_pack', (int) $pack['price_paise'], $packId, [
            'name' => (string) $pack['name'],
            'credits' => (int) $pack['credits'],
            'bonus_credits' => (int) $pack['bonus_credits'],
        ], 'creditpack:' . (int) $u['id'] . ':' . $packId . ':' . random_hex(4));
        Http::ok($intent, 201);
        return;
    }

    // ---- VIP purchase ----
    if ($path === '/vip/purchase' && $method === 'POST') {
        $u = Auth::authenticate();
        $b = Http::jsonBody();
        $planId = (int) ($b['plan_id'] ?? 0);
        $plan = Db::one('SELECT * FROM vip_plans WHERE id = ? AND is_active = 1', [$planId]);
        if ($plan === null) {
            throw new ApiError('not_found', 'VIP plan not found', 404);
        }
        $intent = Payments::createIntent((int) $u['id'], 'vip', (int) $plan['price_paise'], $planId, [
            'plan_id' => $planId, 'plan_name' => (string) $plan['name'],
        ], 'vip:' . (int) $u['id'] . ':' . $planId . ':' . random_hex(4));
        Http::ok($intent, 201);
        return;
    }

    // ---- Uploads for Study AI (user's own files) ----
    if ($path === '/uploads' && $method === 'POST') {
        $u = Auth::authenticate();
        $doc = handleUserUpload((int) $u['id']);
        Http::ok(['document' => $doc], 201);
        return;
    }

    // ---- Viewer session (auth) ----
    if ($path === '/viewer/session' && $method === 'POST') {
        $u = Auth::authenticate();
        RateLimit::forUser('viewer', (int) $u['id'], 30, 60);
        $b = Http::jsonBody();
        $productId = (int) ($b['product_id'] ?? 0);
        Http::ok(['session' => PdfVault::issueViewerSession((int) $u['id'], $productId)]);
        return;
    }

    // ---- Study / Support / Help AI ----
    if ($path === '/ai/study' && $method === 'POST') {
        $u = Auth::authenticate();
        RateLimit::forUser('ai-study', (int) $u['id'], 30, 60);
        $b = Http::jsonBody();
        $source = [
            'type' => (string) ($b['source_type'] ?? ''),
            'product_id' => (int) ($b['product_id'] ?? 0),
            'document_id' => (int) ($b['document_id'] ?? 0),
            'text' => (string) ($b['text'] ?? ''),
        ];
        // Chat history for multi-turn conversations (capped server-side).
        $history = [];
        if (isset($b['history']) && is_array($b['history'])) {
            foreach (array_slice($b['history'], -12) as $h) {
                if (!is_array($h)) {
                    continue;
                }
                $t = trim((string) ($h['text'] ?? ''));
                if ($t === '') {
                    continue;
                }
                $role = (string) ($h['role'] ?? 'user');
                $history[] = [
                    'role' => ($role === 'model' || $role === 'assistant') ? 'model' : 'user',
                    'text' => mb_substr($t, 0, 4000),
                ];
            }
        }
        $question = (string) ($b['question'] ?? $b['message'] ?? '');
        Http::ok(StudyAi::ask((int) $u['id'], $source, $question, (string) ($b['task'] ?? 'concept'), $history));
        return;
    }
    if ($path === '/ai/support' && $method === 'POST') {
        $u = Auth::authenticate();
        RateLimit::forUser('ai-support', (int) $u['id'], 20, 60);
        $b = Http::jsonBody();
        Http::ok(Support::supportAi((int) $u['id'], (string) ($b['message'] ?? '')));
        return;
    }
    if ($path === '/ai/help' && $method === 'POST') {
        $u = Auth::authenticate();
        RateLimit::forUser('ai-help', (int) $u['id'], 20, 60);
        $b = Http::jsonBody();
        Http::ok(Support::helpAi((int) $u['id'], (string) ($b['message'] ?? '')));
        return;
    }

    // ---- Notifications ----
    if ($path === '/notifications' && $method === 'GET') {
        $u = Auth::authenticate();
        Http::ok([
            'notifications' => Notifications::forUser((int) $u['id']),
            'unread' => Notifications::unreadCount((int) $u['id']),
        ]);
        return;
    }
    if ($path === '/notifications/read-all' && $method === 'POST') {
        $u = Auth::authenticate();
        Notifications::markAllRead((int) $u['id']);
        Http::ok([]);
        return;
    }
    if (preg_match('#^/notifications/(\d+)/read$#', $path, $m) && $method === 'POST') {
        $u = Auth::authenticate();
        Notifications::markRead((int) $u['id'], (int) $m[1]);
        Http::ok([]);
        return;
    }

    // ---- Support (human) ----
    if ($path === '/support' && $method === 'GET') {
        $u = Auth::authenticate();
        Http::ok(['threads' => Support::threadsForUser((int) $u['id'])]);
        return;
    }
    if ($path === '/support' && $method === 'POST') {
        $u = Auth::authenticate();
        $b = Http::jsonBody();
        $id = Support::createThread((int) $u['id'], (string) ($b['subject'] ?? ''), (string) ($b['message'] ?? ''));
        Http::ok(['thread_id' => $id], 201);
        return;
    }
    if (preg_match('#^/support/(\d+)$#', $path, $m) && $method === 'GET') {
        $u = Auth::authenticate();
        Http::ok(Support::threadForUser((int) $u['id'], (int) $m[1]));
        return;
    }
    if (preg_match('#^/support/(\d+)/messages$#', $path, $m) && $method === 'POST') {
        $u = Auth::authenticate();
        $b = Http::jsonBody();
        Support::userMessage((int) $u['id'], (int) $m[1], (string) ($b['message'] ?? ''));
        Http::ok([]);
        return;
    }

    // ---- Admin ----
    if (strpos($path, '/admin') === 0) {
        adminRoutes($path, $method);
        return;
    }

    throw new ApiError('not_found', 'Endpoint not found', 404);
}

// ===========================================================================
function adminRoutes(string $path, string $method): void
{
    $admin = Auth::authenticateAdmin();
    $adminId = (int) $admin['id'];

    // Identity for the dedicated (email+password) admin session.
    if ($path === '/admin/auth/me' && $method === 'GET') {
        Http::ok(['admin' => $admin]);
        return;
    }
    // Manual gateway recheck — re-verifies the latest payment intent against Cashfree.
    if (preg_match('#^/admin/orders/(\d+)/recheck$#', $path, $m) && $method === 'POST') {
        Http::ok(Payments::recheckForAdmin((int) $m[1], $adminId));
        return;
    }

    if ($path === '/admin/stats' && $method === 'GET') {
        Http::ok(['stats' => Admin::stats()]);
        return;
    }
    if ($path === '/admin/users' && $method === 'GET') {
        Http::ok(['users' => Admin::listUsers(Http::query('search'), Http::query('status'), (int) (Http::query('limit') ?? '50'), (int) (Http::query('offset') ?? '0'))]);
        return;
    }
    if (preg_match('#^/admin/users/(\d+)$#', $path, $m) && $method === 'GET') {
        Http::ok(['user' => Admin::getUser((int) $m[1])]);
        return;
    }
    if (preg_match('#^/admin/users/(\d+)/status$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        Admin::setUserStatus($adminId, (int) $m[1], (string) ($b['status'] ?? ''), (string) ($b['reason'] ?? ''));
        Http::ok([]);
        return;
    }
    if (preg_match('#^/admin/users/(\d+)/wallet$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        $amount = (int) ($b['amount_paise'] ?? 0);
        $balance = Admin::adjustWallet($adminId, (int) $m[1], $amount, (string) ($b['reason'] ?? 'manual adjustment'));
        Http::ok(['balance_paise' => $balance]);
        return;
    }
    if (preg_match('#^/admin/users/(\d+)/credits$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        $amount = (int) ($b['amount'] ?? 0);
        $balance = Admin::adjustCredits($adminId, (int) $m[1], $amount, (string) ($b['reason'] ?? 'manual adjustment'));
        Http::ok(['balance' => $balance]);
        return;
    }
    if (preg_match('#^/admin/users/(\d+)/pdf/grant$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        Admin::grantPdf($adminId, (int) $m[1], (int) ($b['product_id'] ?? 0));
        Http::ok([]);
        return;
    }
    if (preg_match('#^/admin/users/(\d+)/pdf/revoke$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        Admin::revokePdf($adminId, (int) $m[1], (int) ($b['product_id'] ?? 0));
        Http::ok([]);
        return;
    }
    if (preg_match('#^/admin/users/(\d+)/vip$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        Admin::grantVip($adminId, (int) $m[1], (int) ($b['plan_id'] ?? 0));
        Http::ok([]);
        return;
    }

    if ($path === '/admin/credits/packs' && $method === 'GET') {
        Http::ok(['packs' => Db::all('SELECT * FROM credit_packs ORDER BY price_paise')]);
        return;
    }
    if ($path === '/admin/credits/packs' && $method === 'POST') {
        $b = Http::jsonBody();
        $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', (string) ($b['code'] ?? '')));
        $name = trim((string) ($b['name'] ?? ''));
        $credits = max(0, (int) ($b['credits'] ?? 0));
        $bonus = max(0, (int) ($b['bonus_credits'] ?? 0));
        $price = max(0, (int) ($b['price_paise'] ?? 0));
        if ($name === '' || $credits <= 0 || $price <= 0) {
            throw new ApiError('invalid_input', 'Name, credits and price are required', 400);
        }
        if ($code === '') {
            $code = 'pack_' . random_hex(4);
        }
        try {
            Db::run(
                'INSERT INTO credit_packs (code, name, credits, bonus_credits, price_paise, currency, is_active)
                 VALUES (?,?,?,?,?,?,?)',
                [substr($code, 0, 40), substr($name, 0, 120), $credits, $bonus, $price,
                 strtoupper((string) ($b['currency'] ?? 'INR')), 1]
            );
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new ApiError('conflict', 'A pack with this code already exists', 409);
            }
            throw $e;
        }
        Admin::audit($adminId, 'credit_pack_create', 'credit_packs', (string) Db::insertId(), null, $b);
        Http::ok(['pack' => Db::one('SELECT * FROM credit_packs WHERE id = ?', [Db::insertId()])], 201);
        return;
    }
    if (preg_match('#^/admin/credits/packs/(\d+)$#', $path, $m) && $method === 'PATCH') {
        $id = (int) $m[1];
        $before = Db::one('SELECT * FROM credit_packs WHERE id = ?', [$id]);
        if ($before === null) {
            throw new ApiError('not_found', 'Pack not found', 404);
        }
        $b = Http::jsonBody();
        $fields = [];
        $params = [];
        $map = [
            'name' => 'string', 'credits' => 'int', 'bonus_credits' => 'int',
            'price_paise' => 'int', 'is_active' => 'bool', 'currency' => 'string',
        ];
        foreach ($map as $col => $kind) {
            if (!array_key_exists($col, $b)) {
                continue;
            }
            $fields[] = $col . ' = ?';
            if ($kind === 'int') {
                $params[] = $col === 'credits' ? max(0, (int) $b[$col]) : (int) $b[$col];
            } elseif ($kind === 'bool') {
                $params[] = $b[$col] ? 1 : 0;
            } else {
                $params[] = $col === 'name' ? substr(trim((string) $b[$col]), 0, 120) : strtoupper((string) $b[$col]);
            }
        }
        if ($fields) {
            $params[] = $id;
            Db::run('UPDATE credit_packs SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
            Admin::audit($adminId, 'credit_pack_update', 'credit_packs', (string) $id, $before, $b);
        }
        Http::ok(['pack' => Db::one('SELECT * FROM credit_packs WHERE id = ?', [$id])]);
        return;
    }
    if (preg_match('#^/admin/credits/packs/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $id = (int) $m[1];
        $before = Db::one('SELECT * FROM credit_packs WHERE id = ?', [$id]);
        if ($before === null) {
            throw new ApiError('not_found', 'Pack not found', 404);
        }
        Db::run('UPDATE credit_packs SET is_active = 0 WHERE id = ?', [$id]);
        Admin::audit($adminId, 'credit_pack_archive', 'credit_packs', (string) $id, $before, ['is_active' => 0]);
        Http::ok([]);
        return;
    }

    // Products
    if ($path === '/admin/products' && $method === 'GET') {
        Http::ok(['products' => Db::all('SELECT * FROM products ORDER BY id DESC LIMIT 200')]);
        return;
    }
    if ($path === '/admin/products/upload-pdf' && $method === 'POST') {
        Http::ok(handleAdminPdfUpload());
        return;
    }
    if ($path === '/admin/products/upload-cover' && $method === 'POST') {
        Http::ok(handleAdminCoverUpload());
        return;
    }
    if ($path === '/admin/products' && $method === 'POST') {
        $b = Http::jsonBody();
        $id = Store::create($b);
        Admin::audit($adminId, 'product_create', 'product', $id, null, ['title' => $b['title'] ?? '']);
        Http::ok(['id' => $id], 201);
        return;
    }
    if (preg_match('#^/admin/products/(\d+)$#', $path, $m) && $method === 'PATCH') {
        $b = Http::jsonBody();
        Store::update((int) $m[1], $b);
        Admin::audit($adminId, 'product_update', 'product', (int) $m[1], null, array_keys($b));
        Http::ok([]);
        return;
    }
    if (preg_match('#^/admin/products/(\d+)$#', $path, $m) && $method === 'DELETE') {
        Store::delete((int) $m[1]);
        Admin::audit($adminId, 'product_unpublish', 'product', (int) $m[1]);
        Http::ok([]);
        return;
    }

    // Orders
    if ($path === '/admin/orders' && $method === 'GET') {
        Http::ok(['orders' => Admin::listOrders(Http::query('status'))]);
        return;
    }
    if (preg_match('#^/admin/orders/(\d+)/refund$#', $path, $m) && $method === 'POST') {
        Orders::refund((int) $m[1], $adminId);
        Admin::audit($adminId, 'order_refund', 'order', (int) $m[1]);
        Http::ok([]);
        return;
    }

    // Support
    if ($path === '/admin/support' && $method === 'GET') {
        Http::ok(['threads' => Support::threadsForAdmin(Http::query('status'))]);
        return;
    }
    if (preg_match('#^/admin/support/(\d+)$#', $path, $m) && $method === 'GET') {
        Http::ok(Support::threadForAdmin((int) $m[1]));
        return;
    }
    if (preg_match('#^/admin/support/(\d+)/reply$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        Support::adminReply((int) $m[1], (string) ($b['message'] ?? ''));
        Http::ok([]);
        return;
    }
    if (preg_match('#^/admin/support/(\d+)/status$#', $path, $m) && $method === 'POST') {
        $b = Http::jsonBody();
        Support::setStatus((int) $m[1], (string) ($b['status'] ?? ''));
        Http::ok([]);
        return;
    }

    // Notifications
    if ($path === '/admin/notify' && $method === 'POST') {
        $b = Http::jsonBody();
        Admin::notifyUser($adminId, (int) ($b['user_id'] ?? 0), (string) ($b['category'] ?? 'system'), (string) ($b['title'] ?? ''), (string) ($b['body'] ?? ''));
        Http::ok([]);
        return;
    }
    if ($path === '/admin/broadcast' && $method === 'POST') {
        $b = Http::jsonBody();
        Admin::broadcast($adminId, (string) ($b['category'] ?? 'system'), (string) ($b['title'] ?? ''), (string) ($b['body'] ?? ''));
        Http::ok([]);
        return;
    }

    // Settings
    if ($path === '/admin/settings' && $method === 'GET') {
        Http::ok(['settings' => Admin::allSettings()]);
        return;
    }
    if ($path === '/admin/settings' && $method === 'POST') {
        $b = Http::jsonBody();
        Admin::saveSettings($adminId, $b['settings'] ?? []);
        Http::ok([]);
        return;
    }

    // Audit
    if ($path === '/admin/audit' && $method === 'GET') {
        Http::ok(['logs' => Admin::auditLog()]);
        return;
    }
    // VIP plans (admin manage)
    if ($path === '/admin/vip/plans' && $method === 'GET') {
        Http::ok(['plans' => Db::all('SELECT * FROM vip_plans ORDER BY price_paise')]);
        return;
    }
    if ($path === '/admin/vip/plans' && $method === 'POST') {
        $b = Http::jsonBody();
        if (!empty($b['id'])) {
            Db::run('UPDATE vip_plans SET name=?, price_paise=?, is_active=?, benefits=?, fair_use=? WHERE id=?',
                [$b['name'] ?? '', (int) ($b['price_paise'] ?? 0), !empty($b['is_active']) ? 1 : 0,
                 json_encode($b['benefits'] ?? new stdClass()), json_encode($b['fair_use'] ?? new stdClass()), (int) $b['id']]);
            Admin::audit($adminId, 'vip_plan_update', 'vip_plan', (int) $b['id']);
            Http::ok(['id' => (int) $b['id']]);
            return;
        }
        Db::run('INSERT INTO vip_plans (code,name,interval,price_paise,currency,benefits,fair_use,is_active) VALUES (?,?,?,?,?,?,?,?)',
            [$b['code'] ?? ('plan_' . random_hex(4)), $b['name'] ?? '', $b['interval'] ?? 'monthly',
             (int) ($b['price_paise'] ?? 0), 'INR', json_encode($b['benefits'] ?? new stdClass()),
             json_encode($b['fair_use'] ?? new stdClass()), !empty($b['is_active']) ? 1 : 0]);
        $id = Db::insertId();
        Admin::audit($adminId, 'vip_plan_create', 'vip_plan', $id);
        Http::ok(['id' => $id], 201);
        return;
    }

    throw new ApiError('not_found', 'Admin endpoint not found', 404);
}

// ===========================================================================
function optionalUserId(): ?int
{
    $token = Http::bearerToken();
    if ($token === null) {
        return null;
    }
    try {
        $claims = Auth::verifyIdToken($token);
        $user = Auth::syncUser((string) $claims['sub'], $claims);
        return (int) $user['id'];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve a stored media reference into a URL the client can load.
 *  - Absolute http(s) / data: URLs (e.g. Google sign-in photo) pass through.
 *  - Relative storage paths (e.g. "avatars/x.png") are streamed through the
 *    public media endpoint so raw storage/ URLs are never exposed.
 */
function media_url(?string $url): ?string
{
    if ($url === null || $url === '') {
        return null;
    }
    if (preg_match('#^(https?:)?//#i', $url) === 1 || strncmp($url, 'data:', 5) === 0) {
        return $url;
    }
    return '/api/cover?f=' . rawurlencode(ltrim($url, '/'));
}

/**
 * Profile picture upload (multipart `file` or JSON `avatar_base64` data URL).
 * Files are stored under storage/public/avatars/ (never web-served directly)
 * and served through /api/cover. Max 2 MB, image/* only, re-validated server-side.
 */
function handleAvatarUpload(int $userId): array
{
    $data = null;
    $mime = '';

    if (!empty($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $f = $_FILES['file'];
        $size = (int) $f['size'];
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            throw new ApiError('upload_too_large', 'Profile picture must be under 2 MB', 413);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        $data = file_get_contents($f['tmp_name']);
    } else {
        $b = Http::jsonBody();
        $raw = (string) ($b['avatar_base64'] ?? '');
        if ($raw === '') {
            throw new ApiError('invalid_input', 'Provide a file (field "file") or "avatar_base64"', 400);
        }
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,#i', $raw, $m)) {
            $mime = strtolower($m[1]);
            $raw = substr($raw, strpos($raw, ',') + 1);
        } else {
            $mime = 'image/png';
        }
        $data = base64_decode($raw, true);
        if ($data === false) {
            throw new ApiError('invalid_input', 'Invalid base64 image data', 400);
        }
        if (strlen($data) > 2 * 1024 * 1024) {
            throw new ApiError('upload_too_large', 'Profile picture must be under 2 MB', 413);
        }
    }

    if ($data === null || $data === '') {
        throw new ApiError('upload_failed', 'Could not read the uploaded image', 400);
    }
    // Sniff the actual content — never trust the client-declared MIME.
    $info = @getimagesizefromstring($data);
    if ($info === false) {
        throw new ApiError('unsupported_type', 'Only PNG, JPEG, WebP or GIF images are allowed', 415);
    }
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $type = (int) ($info[2] ?? 0);
    if (!isset($allowed[$type])) {
        throw new ApiError('unsupported_type', 'Only PNG, JPEG, WebP or GIF images are allowed', 415);
    }

    $dir = PUBLIC_MEDIA_DIR . '/avatars';
    ensureDir($dir);
    $name = random_hex(12) . '.' . $allowed[$type];
    $dest = $dir . '/' . $name;
    if (file_put_contents($dest, $data) === false) {
        throw new ApiError('upload_failed', 'Could not save the profile picture', 500);
    }
    @chmod($dest, 0644);

    $prev = Db::one('SELECT avatar_url FROM users WHERE id = ?', [$userId]);
    Db::run('UPDATE users SET avatar_url = ? WHERE id = ?', ['avatars/' . $name, $userId]);

    // Best-effort cleanup of a previously uploaded DP (never touch Google URLs).
    $old = $prev['avatar_url'] ?? null;
    if (is_string($old) && strncmp($old, 'avatars/', 8) === 0) {
        $oldPath = PUBLIC_MEDIA_DIR . '/' . $old;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $u = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
    return userShape($u);
}

function userShape(array $u): array
{
    return [
        'id' => (int) $u['id'],
        'name' => (string) $u['name'],
        'email' => (string) $u['email'],
        'phone' => $u['phone'] !== null ? (string) $u['phone'] : null,
        'avatar_url' => media_url($u['avatar_url'] !== null ? (string) $u['avatar_url'] : null),
        'role' => (string) $u['role'],
        'status' => (string) $u['status'],
        'wallet_balance_paise' => (int) $u['wallet_balance_paise'],
        'ai_credit_balance' => (int) $u['ai_credit_balance'],
        'vip_active' => (bool) $u['vip_active'],
        'vip_expires_at' => $u['vip_expires_at'] !== null ? (string) $u['vip_expires_at'] : null,
    ];
}

// ---- File handling --------------------------------------------------------
function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

function handleUserUpload(int $userId): array
{
    if (empty($_FILES['file']) || !is_array($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ApiError('upload_failed', 'No file uploaded', 400);
    }
    $f = $_FILES['file'];
    $size = (int) $f['size'];
    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        throw new ApiError('upload_too_large', 'File must be under 15 MB', 413);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    $allowed = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new ApiError('unsupported_type', 'Only PDF or image files are allowed', 415);
    }
    ensureDir(UPLOADS_DIR . '/' . $userId);
    $name = random_hex(16) . '.' . $allowed[$mime];
    $rel = $userId . '/' . $name;
    $dest = UPLOADS_DIR . '/' . $rel;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new ApiError('upload_failed', 'Could not save file', 500);
    }
    @chmod($dest, 0640);
    Db::run(
        'INSERT INTO ai_documents (user_id, kind, filename, mime, size_bytes, storage_path) VALUES (?,?,?,?,?,?)',
        [$userId, 'upload', substr((string) ($f['name'] ?? 'upload'), 0, 255), $mime, $size, $rel]
    );
    return [
        'id' => Db::insertId(),
        'filename' => substr((string) ($f['name'] ?? 'upload'), 0, 255),
        'mime' => $mime,
        'size_bytes' => $size,
    ];
}

function handleAdminPdfUpload(): array
{
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ApiError('upload_failed', 'No PDF uploaded', 400);
    }
    $f = $_FILES['file'];
    $tmp = $f['tmp_name'];
    $size = (int) $f['size'];
    if ($size <= 0 || $size > 60 * 1024 * 1024) {
        throw new ApiError('upload_too_large', 'PDF must be under 60 MB', 413);
    }
    $head = (string) fread(fopen($tmp, 'rb'), 5);
    if (strncmp($head, '%PDF-', 5) !== 0) {
        throw new ApiError('unsupported_type', 'File is not a valid PDF', 415);
    }
    ensureDir(PDFS_DIR);
    $name = random_hex(20) . '.pdf';
    $dest = PDFS_DIR . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new ApiError('upload_failed', 'Could not save PDF', 500);
    }
    @chmod($dest, 0640);
    return [
        'pdf_path' => $name,
        'file_size_bytes' => $size,
        'pdf_sha256' => hash_file('sha256', $dest),
    ];
}

function handleAdminCoverUpload(): array
{
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ApiError('upload_failed', 'No image uploaded', 400);
    }
    $f = $_FILES['file'];
    $tmp = $f['tmp_name'];
    $size = (int) $f['size'];
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new ApiError('upload_too_large', 'Image must be under 5 MB', 413);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, $tmp);
    finfo_close($finfo);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new ApiError('unsupported_type', 'Only PNG/JPG/WebP images allowed', 415);
    }
    ensureDir(PUBLIC_MEDIA_DIR . '/covers');
    $name = 'covers/' . random_hex(16) . '.' . $allowed[$mime];
    $dest = PUBLIC_MEDIA_DIR . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new ApiError('upload_failed', 'Could not save image', 500);
    }
    @chmod($dest, 0644);
    return ['thumbnail_path' => $name];
}

function streamCover(string $rel): void
{
    // Only serve files that live inside PUBLIC_MEDIA_DIR (no traversal).
    $rel = ltrim($rel, '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        throw new ApiError('not_found', 'Not found', 404);
    }
    $base = realpath(PUBLIC_MEDIA_DIR);
    $path = realpath(PUBLIC_MEDIA_DIR . '/' . $rel);
    if ($base === false || $path === false || strncmp($path, $base, strlen($base)) !== 0 || !is_file($path)) {
        throw new ApiError('not_found', 'Not found', 404);
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
    $type = $types[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
