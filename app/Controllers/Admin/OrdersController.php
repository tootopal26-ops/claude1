<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminOrders;
use App\Services\Audit;
use App\Services\Fulfilment;

final class OrdersController extends BaseController
{
    public function list(Request $req, array $p): Response
    {
        $q = [];
        foreach (['filter', 'status', 'mode', 'platform', 'q', 'from', 'to'] as $k) {
            $q[$k] = $req->queryString($k, '', 100);
        }
        $q['page'] = $req->queryInt('page', 1, 1, 100000);
        $q['per'] = $req->queryInt('per', 25, 10, 100);
        return $this->ok(AdminOrders::list($q));
    }

    public function show(Request $req, array $p): Response
    {
        return $this->ok(AdminOrders::detail($this->id($p)));
    }

    /** POST /admin/api/orders/{id}/action  { action, note?, amount?, reference? } */
    public function act(Request $req, array $p): Response
    {
        $id = $this->id($p);
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'order-action', static fn(): array => AdminOrders::act($id, $in, $req, $admin));
    }

    public function approvable(Request $req, array $p): Response
    {
        return $this->ok(['count' => Fulfilment::approvableCount()]);
    }

    /**
     * POST /admin/api/orders/approve-all  { confirm: true }  + Idempotency-Key (required)
     * Approves every eligible manual order in one transaction.
     */
    public function approveAll(Request $req, array $p): Response
    {
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
        return $this->ok(AdminOrders::payments([
            'status' => $req->queryString('status', '', 24), 'q' => $req->queryString('q', '', 60), 'page' => $req->queryInt('page', 1, 1, 100000),
        ]));
    }
}
