<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\DailyReport;
use App\Support\Permissions;
use App\Support\Tz;

/**
 * Daily reports: the history and one report in full. Reading needs `reports.view` (owner, manager, analyst — the roles that may see profit);
 * generating a missing report or regenerating one needs `reports.generate` (owner, manager) and is audited.
 */
final class ReportsController extends BaseController
{
    /** @return array<string,bool> */
    private function can(Request $req): array
    {
        return ['generate' => Permissions::can((string) $this->admin($req)['role'], 'reports.generate')];
    }

    /** GET /admin/api/reports?from&to&page */
    public function list(Request $req, array $p): Response
    {
        $this->authorize($req, 'reports.view');
        return $this->ok(DailyReport::list([
            'from' => $req->queryString('from', '', 10), 'to' => $req->queryString('to', '', 10), 'page' => $req->queryInt('page', 1, 1, 100000),
        ]) + ['can' => $this->can($req), 'timezone' => Tz::label(), 'latest_day' => Tz::addDays(Tz::today(), -1)]);
    }

    /** GET /admin/api/reports/{date} */
    public function show(Request $req, array $p): Response
    {
        $this->authorize($req, 'reports.view');
        $report = DailyReport::get((string) ($p['date'] ?? ''));
        if ($report === null) {
            throw new HttpException(404, 'There is no report for that day.');
        }
        return $this->ok(['report' => $report, 'can' => $this->can($req)]);
    }

    /** POST /admin/api/reports/{date}/generate  { force? } — create the report of a finished day, or (force) rebuild it */
    public function generate(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'reports.generate');
        $day = (string) ($p['date'] ?? '');
        $force = ($req->json()['force'] ?? false) === true;
        return $this->once($req, 'report-generate', static function () use ($day, $force, $admin, $req): array {
            $r = DailyReport::generate($day, ['by' => 'admin', 'force' => $force, 'admin' => $admin, 'req' => $req]);
            $message = $r['created'] ? 'Report created.' : ($r['regenerated']
                ? ($r['changed'] ? 'Report rebuilt: ' . count($r['changed']) . ' figure' . (count($r['changed']) === 1 ? '' : 's') . ' changed.' : 'Report rebuilt: no figure changed.')
                : 'This day already has a report. Use Regenerate to rebuild it.');
            return ['result' => $r, 'message' => $message, 'report' => DailyReport::get($day)];
        });
    }
}
