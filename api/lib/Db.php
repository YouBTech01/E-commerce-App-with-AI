<?php
declare(strict_types=1);

namespace ExamLegacy;

use PDO;
use PDOException;

/**
 * PDO singleton with safe defaults. All queries use prepared statements.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        // Spec §3: smart local failover — if the configured remote host is
        // unreachable, automatically retry against 127.0.0.1 before failing.
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
        $hosts = [DB_HOST];
        if (DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost') {
            $hosts[] = '127.0.0.1';
        }
        $last = null;
        foreach ($hosts as $host) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, DB_PORT, DB_NAME, DB_CHARSET);
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                if ($host !== DB_HOST) {
                    error_log('[ExamLegacy] DB failover: remote host unreachable, connected via 127.0.0.1');
                }
                return self::$pdo;
            } catch (PDOException $e) {
                $last = $e;
                error_log('[ExamLegacy] DB connection failed (' . $host . '): ' . $e->getMessage());
            }
        }
        throw new \RuntimeException('Database unavailable', 0, $last);
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function scalar(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** Execute a callback inside a transaction; rolls back on exception.
     *  Nested calls join the existing transaction instead of crashing with
     *  "There is already an active transaction". */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $nested = $pdo->inTransaction();
        if (!$nested) {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn($pdo);
            if (!$nested) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            // Only the outermost frame rolls back; inner frames re-throw and
            // let the owner of the real transaction decide.
            if (!$nested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
