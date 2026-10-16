<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\AdminAccount;
use App\Services\Dashboard;
use App\Support\Permissions;

final class DashboardController extends BaseController
{
    /** GET /admin/api/dashboard?range=today|7d|30d|90d|lifetime|custom&from&to&platform&mode&payment */
    public function data(Request $req, array $p): Response
    {
        $this->authorize($req, 'dashboard.view');
        $filters = [];
        foreach (['range', 'from', 'to', 'platform', 'mode', 'payment'] as $k) {
            $filters[$k] = $req->queryString($k, '', 32);
        }
        if ($filters['range'] === '') {
            $filters['range'] = '7d';
        }
        $d = Dashboard::build($filters);
        // profit, costs and advertising figures are for roles with finance.view; for the others they are removed from the data, not just hidden
        if (!Permissions::can((string) $this->admin($req)['role'], 'finance.view')) {
            $d = Dashboard::withoutFinance($d);
        }
        return $this->ok(['dashboard' => $d]);
    }

    /** GET /admin/api/status — sidebar badge, setup checklist, cron health. */
    public function status(Request $req, array $p): Response
    {
        return $this->ok(['status' => AdminAccount::status()]);
    }
}
