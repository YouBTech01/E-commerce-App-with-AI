<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Support — human in-app conversations + two SEPARATE AI helpers.
 *
 *  - Support AI: account/login/payment/order/wallet/PDF-access/transaction help.
 *    It is grounded ONLY in verified backend data for THIS user; it must not
 *    invent statuses.
 *  - Help AI: general product / website / learning guidance (no personal data).
 *  - Human support: user <-> admin threads with status workflow.
 */
final class Support
{
    // ---------------- Human support ----------------

    public static function createThread(int $userId, string $subject, string $body): int
    {
        $subject = trim(mb_substr($subject, 0, 190));
        $body = trim($body);
        if ($body === '') {
            throw new ApiError('invalid_input', 'Message cannot be empty', 400);
        }
        return Db::transaction(function () use ($userId, $subject, $body) {
            Db::run('INSERT INTO support_threads (user_id, subject, status, last_message_at) VALUES (?,?,?,NOW())',
                [$userId, $subject !== '' ? $subject : 'Support request', 'waiting_admin']);
            $threadId = Db::insertId();
            Db::run('INSERT INTO support_messages (thread_id, sender, body, read_by_user) VALUES (?,?,?,1)',
                [$threadId, 'user', $body]);
            return $threadId;
        });
    }

    public static function userMessage(int $userId, int $threadId, string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            throw new ApiError('invalid_input', 'Message cannot be empty', 400);
        }
        $thread = Db::one('SELECT * FROM support_threads WHERE id = ? AND user_id = ?', [$threadId, $userId]);
        if ($thread === null) {
            throw new ApiError('not_found', 'Conversation not found', 404);
        }
        if (in_array($thread['status'], ['closed'], true)) {
            throw new ApiError('closed', 'This conversation is closed', 409);
        }
        Db::transaction(function () use ($threadId, $body) {
            Db::run('INSERT INTO support_messages (thread_id, sender, body, read_by_user) VALUES (?,?,?,1)',
                [$threadId, 'user', $body]);
            Db::run('UPDATE support_threads SET status = \'waiting_admin\', last_message_at = NOW() WHERE id = ?', [$threadId]);
        });
    }

    public static function threadsForUser(int $userId): array
    {
        $threads = Db::all('SELECT * FROM support_threads WHERE user_id = ? ORDER BY last_message_at DESC, id DESC', [$userId]);
        return array_map(fn($t) => self::threadShape($t, true), $threads);
    }

    public static function threadForUser(int $userId, int $threadId): array
    {
        $thread = Db::one('SELECT * FROM support_threads WHERE id = ? AND user_id = ?', [$threadId, $userId]);
        if ($thread === null) {
            throw new ApiError('not_found', 'Conversation not found', 404);
        }
        // Mark admin messages read by user.
        Db::run('UPDATE support_messages SET read_by_user = 1 WHERE thread_id = ? AND sender <> \'user\'', [$threadId]);
        return [
            'thread' => self::threadShape($thread, true),
            'messages' => self::messages($threadId),
        ];
    }

    // ---- Admin side ----

    public static function threadsForAdmin(?string $status = null): array
    {
        if ($status !== null && $status !== '' && $status !== 'all') {
            $threads = Db::all('SELECT * FROM support_threads WHERE status = ? ORDER BY last_message_at DESC, id DESC', [$status]);
        } else {
            $threads = Db::all('SELECT * FROM support_threads ORDER BY last_message_at DESC, id DESC');
        }
        return array_map(fn($t) => self::threadShape($t, false), $threads);
    }

    public static function threadForAdmin(int $threadId): array
    {
        $thread = Db::one('SELECT * FROM support_threads WHERE id = ?', [$threadId]);
        if ($thread === null) {
            throw new ApiError('not_found', 'Conversation not found', 404);
        }
        Db::run('UPDATE support_messages SET read_by_admin = 1 WHERE thread_id = ? AND sender = \'user\'', [$threadId]);
        return ['thread' => self::threadShape($thread, false), 'messages' => self::messages($threadId)];
    }

    public static function adminReply(int $threadId, string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            throw new ApiError('invalid_input', 'Reply cannot be empty', 400);
        }
        $thread = Db::one('SELECT * FROM support_threads WHERE id = ?', [$threadId]);
        if ($thread === null) {
            throw new ApiError('not_found', 'Conversation not found', 404);
        }
        Db::transaction(function () use ($threadId, $body, $thread) {
            Db::run('INSERT INTO support_messages (thread_id, sender, body, read_by_admin) VALUES (?,?,?,1)',
                [$threadId, 'admin', $body]);
            Db::run('UPDATE support_threads SET status = \'waiting_user\', last_message_at = NOW() WHERE id = ?', [$threadId]);
            Notifications::emit((int) $thread['user_id'], 'support', 'Support replied',
                'You have a new reply from ExamLegacy support.',
                ['thread_id' => $threadId], 'support_reply:' . $threadId . ':' . random_hex(6));
        });
    }

    public static function setStatus(int $threadId, string $status): void
    {
        $allowed = ['open', 'waiting_admin', 'waiting_user', 'resolved', 'closed'];
        if (!in_array($status, $allowed, true)) {
            throw new ApiError('invalid_status', 'Invalid status', 400);
        }
        Db::run('UPDATE support_threads SET status = ? WHERE id = ?', [$status, $threadId]);
    }

    private static function messages(int $threadId): array
    {
        return array_map(fn($m) => [
            'id' => (int) $m['id'],
            'sender' => (string) $m['sender'],
            'body' => (string) $m['body'],
            'created_at' => (string) $m['created_at'],
        ], Db::all('SELECT id, sender, body, created_at FROM support_messages WHERE thread_id = ? ORDER BY id ASC', [$threadId]));
    }

    private static function threadShape(array $t, bool $isUser): array
    {
        $last = Db::scalar('SELECT body FROM support_messages WHERE thread_id = ? ORDER BY id DESC LIMIT 1', [(int) $t['id']]);
        return [
            'id' => (int) $t['id'],
            'subject' => (string) $t['subject'],
            'status' => (string) $t['status'],
            'last_message' => $last !== null ? mb_substr((string) $last, 0, 140) : '',
            'created_at' => (string) $t['created_at'],
            'last_message_at' => $t['last_message_at'] !== null ? (string) $t['last_message_at'] : null,
        ];
    }

    // ---------------- Support AI (grounded) ----------------

    /** Verified, minimal facts for THIS user only. */
    private static function verifiedFacts(int $userId): string
    {
        $user = Db::one('SELECT name, email, status, created_at FROM users WHERE id = ?', [$userId]);
        $orders = Db::all(
            'SELECT order_code, status, total_paise, payment_method, created_at, paid_at
             FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$userId]);
        $walletTx = Db::all(
            'SELECT type, amount_paise, description, created_at FROM wallet_transactions
             WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$userId]);
        $accessCount = (int) Db::scalar(
            "SELECT COUNT(*) FROM pdf_access WHERE user_id = ? AND status = 'active'", [$userId]);
        $revokedCount = (int) Db::scalar(
            "SELECT COUNT(*) FROM pdf_access WHERE user_id = ? AND status IN ('revoked','expired')", [$userId]);

        $facts = [
            'account' => [
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'status' => (string) ($user['status'] ?? ''),
                'member_since' => (string) ($user['created_at'] ?? ''),
            ],
            'wallet_balance_paise' => Wallet::balance($userId),
            'ai_credit_balance' => AiCredits::balance($userId),
            'active_pdf_access' => $accessCount,
            'revoked_or_expired_access' => $revokedCount,
            'recent_orders' => $orders,
            'recent_wallet_transactions' => $walletTx,
        ];
        return json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** Support AI answer, grounded in verified data. Deducts credits. */
    public static function supportAi(int $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new ApiError('invalid_input', 'Please describe your issue', 400);
        }
        $cost = max(0, (int) Settings::get('ai_credit_cost_support', '1'));
        if (AiCredits::balance($userId) < $cost) {
            throw new ApiError('insufficient_credits', 'Not enough AI credits to use Support AI.', 402);
        }
        $system = 'You are ExamLegacy Support AI. You help with account, login, payment, order, wallet, '
                . 'PDF access and transaction issues. You MUST answer using ONLY the verified account data '
                . 'provided below. Never invent order, payment, wallet or access statuses. If the data does not '
                . 'contain the answer, say you cannot confirm it and advise contacting human support. Be concise and clear.';
        $facts = self::verifiedFacts($userId);
        $parts = [
            Gemini::textPart("VERIFIED ACCOUNT DATA (JSON):\n" . $facts),
            Gemini::textPart("User issue: " . $message),
        ];
        Db::run('INSERT INTO ai_usage (user_id, kind, credits_spent, status) VALUES (?,?,?,?)',
            [$userId, 'support', $cost, 'ok']);
        $usageId = Db::insertId();
        try {
            $result = Gemini::generateContent($parts, $system);
        } catch (ApiError $e) {
            Db::run('UPDATE ai_usage SET status = \'error\' WHERE id = ?', [$usageId]);
            throw $e;
        }
        $balance = AiCredits::spend($userId, $cost, $usageId, 'Support AI');
        return ['answer' => $result['text'], 'credits_spent' => $cost, 'balance' => $balance];
    }

    // ---------------- Help AI (general, no personal data) ----------------

    public static function helpAi(int $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new ApiError('invalid_input', 'Please ask a question', 400);
        }
        $cost = max(0, (int) Settings::get('ai_credit_cost_help', '0'));
        if ($cost > 0 && AiCredits::balance($userId) < $cost) {
            throw new ApiError('insufficient_credits', 'Not enough AI credits.', 402);
        }
        $system = 'You are ExamLegacy Help AI. You give general guidance about using the ExamLegacy website, '
                . 'its features (Store, Library, secure PDF viewer, Study AI, Wallet, AI Credits, VIP PASS, Support) '
                . 'and general study/learning tips. You do NOT have access to any personal account data and must not '
                . 'claim to. For account-specific issues, direct the user to Support AI or human support.';
        $parts = [Gemini::textPart($message)];
        Db::run('INSERT INTO ai_usage (user_id, kind, credits_spent, status) VALUES (?,?,?,?)',
            [$userId, 'help', $cost, 'ok']);
        $usageId = Db::insertId();
        try {
            $result = Gemini::generateContent($parts, $system);
        } catch (ApiError $e) {
            Db::run('UPDATE ai_usage SET status = \'error\' WHERE id = ?', [$usageId]);
            throw $e;
        }
        $balance = $cost > 0 ? AiCredits::spend($userId, $cost, $usageId, 'Help AI') : AiCredits::balance($userId);
        return ['answer' => $result['text'], 'credits_spent' => $cost, 'balance' => $balance];
    }
}
