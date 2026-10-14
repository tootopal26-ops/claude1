<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper: real prepared statements only (no emulation, so values are
 * never interpolated into SQL), strict SQL mode, UTC session time zone, and a
 * transaction helper that retries on deadlocks.
 *
 * NOTE: SUM()/AVG() come back from MySQL as DECIMAL → PHP strings. Always cast
 * aggregates with (int) / Db::int().
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $host = (string) Env::get('DB_HOST', 'localhost');
        $port = (int) Env::get('DB_PORT', 3306);
        $name = (string) Env::get('DB_NAME', '');
        $user = (string) Env::get('DB_USER', '');
        $pass = (string) Env::get('DB_PASS', '');

        if ($name === '') {
            throw new \RuntimeException('Database is not configured (DB_NAME is empty). Run the installer or edit .env.');
        }

        // "localhost" makes PDO use the MySQL socket; keep it, cPanel hosts rely on it.
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 8,
        ]);
        // Deterministic behaviour on every host: UTC, strict mode, ONLY_FULL_GROUP_BY. NO_UNSIGNED_SUBTRACTION makes `revenue - cost`
        // negative instead of an "out of range" error when the money columns (UNSIGNED, so they can never be stored negative) are
        // subtracted: a loss-making order must show a loss, and MySQL 8 and MariaDB type CASE expressions differently.
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
            . "sql_mode = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_UNSIGNED_SUBTRACTION'");
        // A stuck lock must become a clean (retried, then reported) error instead of tying a PHP worker up for minutes.
        // Both variables exist on MySQL and MariaDB; max_statement_time is MariaDB-only, so it is best effort.
        foreach (['SET SESSION lock_wait_timeout = 10, innodb_lock_wait_timeout = 8', ...(PHP_SAPI === 'cli' ? [] : ['SET SESSION max_statement_time = 25'])] as $guard) {
            try {
                $pdo->exec($guard);
            } catch (PDOException) {
                // option not supported by this server: carry on
            }
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    /** Diagnostics for the dev-only Server-Timing header: how many statements this request ran and how long they took (ms). */
    public static int $queries = 0;
    public static float $queryMs = 0.0;

    /** Forget the connection (tests / forked workers). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        self::$queries++;
        $t0 = hrtime(true);
        try {
            return self::execute($sql, $params);
        } finally {
            self::$queryMs += (hrtime(true) - $t0) / 1e6;
        }
    }

    /** @param array<int|string,mixed> $params */
    private static function execute(string $sql, array $params): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            // Positional (?) placeholders only: native prepares cannot reuse a named placeholder.
            $stmt->bindValue(is_int($key) ? $key + 1 : $i, $value, $type);
            $i++;
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** @return list<mixed> first column of every row */
    public static function col(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return int affected rows */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        self::assertIdentifiers($cols);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::assertIdentifiers([$table]);
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param list<mixed> $whereParams
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdentifiers(array_merge([$table], array_keys($data)));
        $set = implode(', ', array_map(static fn(string $c): string => "`{$c}` = ?", array_keys($data)));
        return self::exec("UPDATE `{$table}` SET {$set} WHERE {$where}", array_merge(array_values($data), $whereParams));
    }

    /** "?,?,?" placeholder list for IN (...) clauses. */
    public static function in(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function int(mixed $v): int
    {
        return (int) $v;
    }

    /**
     * Run $fn inside a transaction. Nested calls join the outer transaction.
     * Deadlocks / lock-wait timeouts are retried (the whole closure re-runs, so
     * keep it free of side effects other than DB writes).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function tx(callable $fn, int $attempts = 3): mixed
    {
        if (self::$txDepth > 0) {
            return $fn();
        }
        $pdo = self::pdo();
        for ($try = 1; ; $try++) {
            self::$txDepth = 1;
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                self::$txDepth = 0;
                return $result;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                self::$txDepth = 0;
                // Deadlocks fail fast and are worth retrying; a lock-wait TIMEOUT has already cost seconds, so it gets one retry only.
                $kind = self::retryKind($e);
                if ($kind !== null && $try < $attempts && ($kind === 'deadlock' || $try < 2)) {
                    usleep(random_int(20_000, 120_000) * $try);
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function inTransaction(): bool
    {
        return self::$txDepth > 0;
    }

    private static function retryKind(\Throwable $e): ?string
    {
        if (!$e instanceof PDOException) {
            return null;
        }
        return match ((int) ($e->errorInfo[1] ?? 0)) {
            1213 => 'deadlock',
            1205 => 'timeout',
            default => null,
        };
    }

    /** Named advisory lock (cron overlap protection). */
    public static function tryLock(string $name, int $timeout = 0): bool
    {
        return (int) self::val('SELECT GET_LOCK(?, ?)', ['smb:' . $name, $timeout], 0) === 1;
    }

    public static function unlock(string $name): void
    {
        self::val('SELECT RELEASE_LOCK(?)', ['smb:' . $name]);
    }

    /** @param list<string> $names */
    private static function assertIdentifiers(array $names): void
    {
        foreach ($names as $n) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $n)) {
                throw new \InvalidArgumentException('Unsafe SQL identifier: ' . $n);
            }
        }
    }
}
