<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Recovery;
use App\Support\Permissions;

/** Order recovery: the queue of paid orders that are not where they should be, the actions on them, and the retry policy. */
final class RecoveryController extends BaseController
{
    /** @return array<string,bool> what this role may do on the screen (the server enforces it anyway) */
    private function can(Request $req): array
    {
        $role = (string) $this->admin($req)['role'];
        return ['recover' => Permissions::can($role, 'orders.recover'), 'run_job' => Permissions::can($role, 'jobs.run')];
    }

    /** GET /admin/api/recovery?category=&q=&page= */
    public function queue(Request $req, array $p): Response
    {
        $this->authorize($req, 'orders.view');
        $data = Recovery::queue(['category' => $req->queryString('category', '', 16), 'q' => $req->queryString('q', '', 100), 'page' => $req->queryInt('page', 1, 1, 100000)]);
        $can = $this->can($req);
        if (!$can['recover']) {
            foreach ($data['rows'] as $i => $row) {
                $data['rows'][$i]['actions'] = [];       // read-only roles are offered nothing
            }
        }
        return $this->ok($data + ['summary' => Recovery::summary(), 'can' => $can, 'categories' => array_map(static fn(array $c): array => ['label' => $c[0], 'what' => $c[1]], Recovery::CATEGORIES)]);
    }

    /** POST /admin/api/recovery/{id}/action  { action, note?, verified_absent?, provider_order_id? } */
    public function act(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'orders.recover');
        $id = $this->id($p);
        $in = $req->json();
        return $this->once($req, 'recovery-action', static function () use ($id, $in, $req, $admin): array {
            $r = Recovery::act($id, $in, $req, $admin);
            return ['message' => $r['message'], 'result' => $r['result'] ?? null, 'order' => Recovery::one($id), 'summary' => Recovery::summary()];
        });
    }

    /** GET /admin/api/recovery/settings */
    public function settings(Request $req, array $p): Response
    {
        $this->authorize($req, 'orders.view');
        return $this->ok(['policy' => Recovery::policy(), 'summary' => Recovery::summary(), 'can' => $this->can($req)]);
    }

    /** PUT /admin/api/recovery/settings  { max_attempts } */
    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'orders.recover');
        $in = $req->json();
        return $this->once($req, 'recovery-settings', static fn(): array => ['policy' => Recovery::saveSettings($in, $admin, $req)]);
    }
}
