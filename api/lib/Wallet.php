<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Store Wallet — IMMUTABLE ledger for ExamLegacy product purchases only.
 *  - No withdrawal, bank transfer or user-to-user transfer.
 *  - MySQL is authoritative. Ledger rows are append-only.
 *  - Every mutation is atomic (row lock on users) and idempotent (idempotency_key).
 * Money is integer paise.
 */
final class Wallet
{
    public static function balance(int $userId): int
    {
        return (int) (Db::scalar('SELECT wallet_balance_paise FROM users WHERE id = ?', [$userId]) ?? 0);
    }

    /** Credit (increase) the wallet. Idempotent by idempotencyKey. */
    public static function credit(
        int $userId,
        int $amount,
        string $type,            // recharge|refund|bonus|adjustment
        string $idempotencyKey,
        string $description = '',
        ?int $orderId = null,
        ?int $paymentId = null,
        ?string $referenceId = null,
        ?int $createdBy = null
    ): int {
        if ($amount <= 0) {
            throw new ApiError('invalid_amount', 'Amount must be positive', 400);
        }
        if (!in_array($type, ['recharge', 'refund', 'bonus', 'adjustment'], true)) {
            throw new ApiError('invalid_type', 'Invalid wallet transaction type', 400);
        }
        return Db::transaction(function () use ($userId, $amount, $type, $idempotencyKey, $description, $orderId, $paymentId, $referenceId, $createdBy) {
            $user = Db::one('SELECT wallet_balance_paise FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new ApiError('not_found', 'User not found', 404);
            }
            // Idempotency: if this key already exists, return the recorded balance.
            $existing = Db::one('SELECT balance_after_paise FROM wallet_transactions WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) {
                return (int) $existing['balance_after_paise'];
            }
            $balance = (int) $user['wallet_balance_paise'] + $amount;
            Db::run(
                'INSERT INTO wallet_transactions
                    (user_id, type, amount_paise, balance_after_paise, order_id, payment_id, reference_id, idempotency_key, description, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$userId, $type, $amount, $balance, $orderId, $paymentId, $referenceId, $idempotencyKey, $description, $createdBy]
            );
            Db::run('UPDATE users SET wallet_balance_paise = ? WHERE id = ?', [$balance, $userId]);
            return $balance;
        });
    }

    /** Debit (decrease) the wallet. Fails atomically if funds are insufficient. */
    public static function debit(
        int $userId,
        int $amount,
        string $type,            // purchase|adjustment
        string $idempotencyKey,
        string $description = '',
        ?int $orderId = null,
        ?int $createdBy = null
    ): int {
        if ($amount <= 0) {
            throw new ApiError('invalid_amount', 'Amount must be positive', 400);
        }
        if (!in_array($type, ['purchase', 'adjustment'], true)) {
            throw new ApiError('invalid_type', 'Invalid wallet transaction type', 400);
        }
        return Db::transaction(function () use ($userId, $amount, $type, $idempotencyKey, $description, $orderId, $createdBy) {
            $user = Db::one('SELECT wallet_balance_paise FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new ApiError('not_found', 'User not found', 404);
            }
            $existing = Db::one('SELECT balance_after_paise FROM wallet_transactions WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) {
                return (int) $existing['balance_after_paise'];
            }
            $current = (int) $user['wallet_balance_paise'];
            if ($current < $amount) {
                throw new ApiError('insufficient_balance', 'Insufficient wallet balance', 400);
            }
            $balance = $current - $amount;
            Db::run(
                'INSERT INTO wallet_transactions
                    (user_id, type, amount_paise, balance_after_paise, order_id, idempotency_key, description, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$userId, $type, -$amount, $balance, $orderId, $idempotencyKey, $description, $createdBy]
            );
            Db::run('UPDATE users SET wallet_balance_paise = ? WHERE id = ?', [$balance, $userId]);
            return $balance;
        });
    }

    /** Full, append-only transaction history for the user. */
    public static function history(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Db::all(
            'SELECT id, type, amount_paise, balance_after_paise, order_id, reference_id, description, created_at
             FROM wallet_transactions
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            [$userId]
        );
    }
}
