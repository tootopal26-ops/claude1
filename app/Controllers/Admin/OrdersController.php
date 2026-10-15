<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminOrders;
use App\Services\Audit;
use App\Services\Fulfilment;
use App\Support\Permissions;

final class OrdersController extends BaseController
{
    public function list(Request $req, array $p): Response
    {
        $this->authorize($req, 'orders.view');
        $q = [];
        foreach (['filter', 'status', 'mode', 'platform', 'q', 'from', 'to'] as $k) {
            $q[$k] = $req->queryString($k, '', 100);
        }
        $q['page'] = $req->queryInt('page', 1, 1, 100000);
        $q['per'] = $req->queryInt('per', 25, 10, 100);
        return $this->ok($this->limit(AdminOrders::list($q), $req));
    }

    public function show(Request $req, array $p): Response
    {
        $this->authorize($req, 'orders.view');
        return $this->ok($this->limit(AdminOrders::detail($this->id($p)), $req));
    }

    /** POST /admin/api/orders/{id}/action  { action, note?, amount?, reference? } */
    public function act(Request $req, array $p): Response
    {
        $id = $this->id($p);
        $in = $req->json();
        $admin = $this->authorize($req, 'orders.act');
        if (($in['action'] ?? '') === 'confirm_payment') {
            $admin = $this->authorize($req, 'payments.override');   // a manual payment confirmation is a person's decision, never a gateway's: owners only
        }
        return $this->once($req, 'order-action', fn(): array => $this->limit(AdminOrders::act($id, $in, $req, $admin), $req));
    }

    /**
     * Show only the actions this admin's role may take (the server refuses the rest anyway; this keeps buttons honest) and say what they may do.
     *
     * @param array<string,mixed> $data an order list, an order detail or an action result
     * @return array<string,mixed>
     */
    private function limit(array $data, Request $req): array
    {
        $role = (string) $this->admin($req)['role'];
        $may = static fn(array $actions): array => array_values(array_filter($actions, static fn(string $a): bool => match (true) {
            $a === 'confirm_payment' => Permissions::can($role, 'payments.override'),
            default => Permissions::can($role, 'orders.act'),
        }));
        foreach ($data['rows'] ?? [] as $i => $row) {
            $data['rows'][$i]['actions'] = $may($row['actions'] ?? []);
        }
        if (isset($data['order']['actions'])) {
            $data['order']['actions'] = $may($data['order']['actions']);
        }
        if (isset($data['detail']['order']['actions'])) {
            $data['detail']['order']['actions'] = $may($data['detail']['order']['actions']);
        }
        if (isset($data['rows'])) {
            $data['can'] = ['bulk' => Permissions::can($role, 'orders.bulk'), 'act' => Permissions::can($role, 'orders.act')];
        }
        return $data;
    }

    public function approvable(Request $req, array $p): Response
    {
        $this->authorize($req, 'orders.view');
        return $this->ok(['count' => Fulfilment::approvableCount()]);
    }

    /**
     * POST /admin/api/orders/approve-all  { confirm: true }  + Idempotency-Key (required)
     * Approves every eligible manual order in one transaction.
     */
    public function approveAll(Request $req, array $p): Response
    {
        $this->authorize($req, 'orders.bulk');
        $in = $req->json();
        if (($in['confirm'] ?? false) !== true) {
            throw new HttpException(422, 'Please confirm the action.', 'confirm_required');
        }
        $admin = $this->admin($req);
        return $this->once($req, 'approve-all', static function () use ($req, $admin): array {
            $r = Fulfilment::approveAll($admin, $req);
            return [
                'approved' => $r['approved'], 'skipped' => $r['skipped'] ?? 0,
                'message' => $r['approved'] === 0 ? 'There were no eligible manual orders to approve.' : ($r['approved'] === 1 ? '1 order approved.' : $r['approved'] . ' orders approved.'),
                'remaining' => Fulfilment::approvableCount(),
            ];
        }, true);
    }

    public function payments(Request $req, array $p): Response
    {
        $this->authorize($req, 'payments.view');
        return $this->ok(AdminOrders::payments([
            'status' => $req->queryString('status', '', 24), 'q' => $req->queryString('q', '', 60), 'page' => $req->queryInt('page', 1, 1, 100000),
        ]));
    }
}
