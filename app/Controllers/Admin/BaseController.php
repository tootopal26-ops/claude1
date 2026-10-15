<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Idempotency;
use App\Core\Request;
use App\Core\Response;
use App\Support\Permissions;

/** Shared helpers for admin controllers. */
abstract class BaseController
{
    /** @return array{id:int,username:string,email:string,role:string,must_change_password:bool} */
    protected function admin(Request $req): array
    {
        return $req->attributes['admin'] ?? throw new HttpException(401, 'Your session has expired. Please sign in again.', 'unauthenticated');
    }

    /**
     * Require a permission for the signed-in admin (role-based; see App\Support\Permissions). The server decides — hiding a button in the
     * browser is a convenience, never the protection.
     *
     * @return array{id:int,username:string,email:string,role:string,must_change_password:bool}
     */
    protected function authorize(Request $req, string $permission): array
    {
        $admin = $this->admin($req);
        $role = (string) ($admin['role'] ?? '');
        if (!Permissions::can($role, $permission)) {
            throw new HttpException(403, 'Your role (' . ($role !== '' ? $role : 'none') . ') does not allow this: ' . (Permissions::ALL[$permission] ?? $permission) . '.', 'forbidden');
        }
        return $admin;
    }

    /** @return array{admin:array<string,mixed>,session:array<string,mixed>,csrf:string} */
    protected function auth(Request $req): array
    {
        return $req->attributes['auth'] ?? throw new HttpException(401, 'Your session has expired. Please sign in again.', 'unauthenticated');
    }

    /**
     * Run a state-changing action once per Idempotency-Key (double clicks, retries after a
     * timeout). Without a key the action simply runs.
     *
     * @param callable():array<string,mixed> $action returns the JSON body
     */
    protected function once(Request $req, string $scope, callable $action, bool $required = false): Response
    {
        $key = (string) ($req->header('idempotency-key') ?? '');
        if ($key === '') {
            if ($required) {
                throw new HttpException(400, 'Missing idempotency key.', 'bad_idempotency_key');
            }
            return Response::json(['ok' => true] + $action());
        }
        $hash = hash('sha256', $req->method . '|' . $req->path . '|' . $req->body());
        [$status, $body, $replayed] = Idempotency::run(
            'admin:' . $this->admin($req)['id'] . ':' . $scope,
            $key,
            $hash,
            static fn(): array => [200, ['ok' => true] + $action()],
        );
        return Response::json($body, $status, $replayed ? ['X-Idempotent-Replay' => 'true'] : []);
    }

    /** JSON success helper. @param array<string,mixed> $data */
    protected function ok(array $data = [], int $status = 200): Response
    {
        return Response::json(['ok' => true] + $data, $status);
    }

    /** Positive integer from a route parameter. */
    protected function id(array $p, string $name = 'id'): int
    {
        $v = $p[$name] ?? '';
        if (!preg_match('/^\d{1,12}$/D', (string) $v) || (int) $v < 1) {
            throw new HttpException(404, 'Not found.');
        }
        return (int) $v;
    }
}
