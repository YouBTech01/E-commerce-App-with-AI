<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * AI Credits — a SEPARATE ledger from the Store Wallet (not money).
 * Append-only, atomic, idempotent. Drives Study AI / Support AI usage.
 */
final class AiCredits
{
    public static function balance(int $userId): int
    {
        return (int) (Db::scalar('SELECT ai_credit_balance FROM users WHERE id = ?', [$userId]) ?? 0);
    }

    /** Credit AI credits (trial, purchase, adjustment). Idempotent. */
    public static function credit(
        int $userId,
        int $amount,
        string $type,            // trial|purchase|adjustment
        string $idempotencyKey,
        string $description = '',
        ?int $usageId = null,
        ?string $referenceId = null,
        ?int $createdBy = null
    ): int {
        if ($amount <= 0) {
            throw new ApiError('invalid_amount', 'Amount must be positive', 400);
        }
        if (!in_array($type, ['trial', 'purchase', 'adjustment'], true)) {
            throw new ApiError('invalid_type', 'Invalid credit type', 400);
        }
        return Db::transaction(function () use ($userId, $amount, $type, $idempotencyKey, $description, $usageId, $referenceId, $createdBy) {
            $user = Db::one('SELECT ai_credit_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new ApiError('not_found', 'User not found', 404);
            }
            $existing = Db::one('SELECT balance_after FROM ai_credit_transactions WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) {
                return (int) $existing['balance_after'];
            }
            $balance = (int) $user['ai_credit_balance'] + $amount;
            Db::run(
                'INSERT INTO ai_credit_transactions
                    (user_id, type, amount, balance_after, usage_id, reference_id, idempotency_key, description, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$userId, $type, $amount, $balance, $usageId, $referenceId, $idempotencyKey, $description, $createdBy]
            );
            Db::run('UPDATE users SET ai_credit_balance = ? WHERE id = ?', [$balance, $userId]);
            return $balance;
        });
    }

    /**
     * Atomically spend AI credits for a usage record. Records a 'usage' ledger row.
     * Throws insufficient_credits if the user cannot afford it.
     * Returns the new balance.
     */
    public static function spend(int $userId, int $amount, int $usageId, string $description = ''): int
    {
        if ($amount < 0) {
            throw new ApiError('invalid_amount', 'Invalid credit amount', 400);
        }
        if ($amount === 0) {
            return self::balance($userId);
        }
        return Db::transaction(function () use ($userId, $amount, $usageId, $description) {
            $user = Db::one('SELECT ai_credit_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new ApiError('not_found', 'User not found', 404);
            }
            $current = (int) $user['ai_credit_balance'];
            if ($current < $amount) {
                throw new ApiError('insufficient_credits', 'Not enough AI credits. Please top up or upgrade to VIP.', 402);
            }
            $balance = $current - $amount;
            $idem = 'usage:' . $usageId;
            $existing = Db::one('SELECT balance_after FROM ai_credit_transactions WHERE idempotency_key = ?', [$idem]);
            if ($existing !== null) {
                return (int) $existing['balance_after'];
            }
            Db::run(
                'INSERT INTO ai_credit_transactions
                    (user_id, type, amount, balance_after, usage_id, idempotency_key, description)
                 VALUES (?,?,?,?,?,?,?)',
                [$userId, 'usage', -$amount, $balance, $usageId, $idem, $description]
            );
            Db::run('UPDATE users SET ai_credit_balance = ? WHERE id = ?', [$balance, $userId]);
            return $balance;
        });
    }

    /**
     * Debit AI credits directly (admin adjustment). Reduces balance; refuses to
     * go negative. Idempotent by idempotencyKey.
     */
    public static function debit(int $userId, int $amount, string $idempotencyKey, string $description = '', ?int $createdBy = null): int
    {
        if ($amount <= 0) {
            throw new ApiError('invalid_amount', 'Amount must be positive', 400);
        }
        return Db::transaction(function () use ($userId, $amount, $idempotencyKey, $description, $createdBy) {
            $user = Db::one('SELECT ai_credit_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new ApiError('not_found', 'User not found', 404);
            }
            $existing = Db::one('SELECT balance_after FROM ai_credit_transactions WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) {
                return (int) $existing['balance_after'];
            }
            $current = (int) $user['ai_credit_balance'];
            if ($current < $amount) {
                throw new ApiError('insufficient_credits', 'Cannot deduct more credits than the user has', 400);
            }
            $balance = $current - $amount;
            Db::run(
                'INSERT INTO ai_credit_transactions (user_id, type, amount, balance_after, idempotency_key, description, created_by)
                 VALUES (?,?,?,?,?,?,?)',
                [$userId, 'adjustment', -$amount, $balance, $idempotencyKey, $description, $createdBy]
            );
            Db::run('UPDATE users SET ai_credit_balance = ? WHERE id = ?', [$balance, $userId]);
            return $balance;
        });
    }

    public static function history(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Db::all(
            'SELECT id, type, amount, balance_after, description, created_at
             FROM ai_credit_transactions
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            [$userId]
        );
    }
}
