<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * In-app notifications with idempotency (event_key unique) so duplicate
 * events / webhooks never create duplicate notifications.
 * user_id = NULL broadcasts to all users.
 */
final class Notifications
{
    /**
     * Create a notification idempotently. Returns the notification id or null
     * if an event with the same key already exists.
     */
    public static function emit(
        ?int $userId,
        string $category,
        string $title,
        string $body,
        array $data = [],
        ?string $eventKey = null
    ): ?int {
        try {
            Db::run(
                'INSERT INTO notifications (user_id, category, title, body, data, event_key)
                 VALUES (?,?,?,?,?,?)',
                [
                    $userId,
                    $category,
                    $title,
                    $body,
                    empty($data) ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
                    $eventKey,
                ]
            );
            return Db::insertId();
        } catch (\PDOException $e) {
            // Duplicate event_key (1062) => already emitted; ignore.
            if ($e->errorInfo[1] ?? 0 === 1062) {
                return null;
            }
            throw $e;
        }
    }

    public static function broadcast(string $category, string $title, string $body, array $data = [], ?string $eventKey = null): ?int
    {
        return self::emit(null, $category, $title, $body, $data, $eventKey);
    }

    /** Notifications for a user: their own + broadcasts (user_id IS NULL). */
    public static function forUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Db::all(
            'SELECT id, category, title, body, data, is_read, created_at
             FROM notifications
             WHERE user_id = ? OR user_id IS NULL
             ORDER BY id DESC
             LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            [$userId]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [$userId]
        );
    }

    public static function markRead(int $userId, int $notificationId): void
    {
        Db::run(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)',
            [$notificationId, $userId]
        );
    }

    public static function markAllRead(int $userId): void
    {
        Db::run(
            'UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [$userId]
        );
    }
}
