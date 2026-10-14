<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Clock;

/**
 * Makes "important" actions safe against double clicks, retries and parallel
 * requests: checkout, Approve All, settings/service saves.
 *
 * The first request with a given (scope, key) runs the action and its response
 * is stored; identical repeats get the stored response instead of re-running.
 * A repeat that arrives while the first is still running waits briefly, then
 * answers 409 so the client can retry.
 */
final class Idempotency
{
    /**
     * @param callable():array{0:int,1:array<string,mixed>} $action returns [httpStatus, body]
     * @return array{0:int,1:array<string,mixed>,2:bool} [status, body, replayed]
     */
    public static function run(string $scope, string $key, string $requestHash, callable $action, int $ttlSeconds = 86400): array
    {
        if (!preg_match('/^[A-Za-z0-9_\-:.]{8,80}$/', $key)) {
            throw new HttpException(400, 'Missing or invalid idempotency key.', 'bad_idempotency_key');
        }
        $k = hash('sha256', $scope . '|' . $key);
        $now = Clock::now();

        $owned = Db::exec(
            "INSERT IGNORE INTO idempotency_keys (k, scope, request_hash, state, created_at, expires_at) VALUES (?, ?, ?, 'processing', ?, ?)",
            [$k, $scope, $requestHash, Clock::sql($now), Clock::sql($now + $ttlSeconds)]
        ) === 1;

        if (!$owned) {
            return self::replayOrWait($k, $requestHash, $scope, $key, $action, $ttlSeconds);
        }

        try {
            [$status, $body] = $action();
        } catch (\Throwable $e) {
            // Let the client retry with the same key after a failure.
            Db::exec('DELETE FROM idempotency_keys WHERE k = ?', [$k]);
            throw $e;
        }

        if ($status >= 500 || $status === 429 || $status === 422 || $status === 400) {
            // Validation / transient failures must not be "remembered" forever.
            Db::exec('DELETE FROM idempotency_keys WHERE k = ?', [$k]);
        } else {
            Db::exec(
                "UPDATE idempotency_keys SET state = 'done', response_code = ?, response_body = ? WHERE k = ?",
                [$status, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $k]
            );
        }
        return [$status, $body, false];
    }

    /** @return array{0:int,1:array<string,mixed>,2:bool} */
    private static function replayOrWait(string $k, string $requestHash, string $scope, string $key, callable $action, int $ttl): array
    {
        $deadline = microtime(true) + 10.0;
        do {
            $row = Db::one('SELECT request_hash, state, response_code, response_body, created_at FROM idempotency_keys WHERE k = ?', [$k]);
            if ($row === null) {
                // The owner failed and cleaned up — we can safely become the owner.
                return self::run($scope, $key, $requestHash, $action, $ttl);
            }
            if (!hash_equals((string) $row['request_hash'], $requestHash)) {
                throw new HttpException(422, 'This idempotency key was already used for a different request.', 'idempotency_mismatch');
            }
            if ($row['state'] === 'done') {
                $body = json_decode((string) $row['response_body'], true);
                return [(int) $row['response_code'], is_array($body) ? $body : [], true];
            }
            // stale 'processing' row (crashed worker) → take over
            if ((Clock::now() - (int) Clock::fromSql((string) $row['created_at'])) > 120) {
                Db::exec('DELETE FROM idempotency_keys WHERE k = ? AND state = ?', [$k, 'processing']);
                return self::run($scope, $key, $requestHash, $action, $ttl);
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        throw new HttpException(409, 'Your request is still being processed. Please wait a moment.', 'in_progress');
    }

    public static function purgeExpired(): int
    {
        return Db::exec('DELETE FROM idempotency_keys WHERE expires_at < ?', [Clock::sql()]);
    }
}
