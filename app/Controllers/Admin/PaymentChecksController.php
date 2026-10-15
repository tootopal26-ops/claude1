<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Risk;
use App\Support\Permissions;

/** Payment checks: the payment monitor, the flags raised against payments, and the thresholds that raise them. */
final class PaymentChecksController extends BaseController
{
    /** What the signed-in admin may do on this page (the buttons are hidden for the rest; the server enforces it either way). */
    private function can(Request $req): array
    {
        $role = (string) $this->admin($req)['role'];
        return [
            'review' => Permissions::can($role, 'payments.review'),
            'override' => Permissions::can($role, 'payments.override'),
            'configure' => Permissions::can($role, 'alerts.manage'),
        ];
    }

    /** GET /admin/api/payment-checks/summary — the numbers at the top of the screen. */
    public function summary(Request $req, array $p): Response
    {
        $this->authorize($req, 'payments.view');
        return $this->ok(['summary' => Risk::summary(), 'can' => $this->can($req)]);
    }

    /** GET /admin/api/payment-checks?state&flag&q&page */
    public function monitor(Request $req, array $p): Response
    {
        $this->authorize($req, 'payments.view');
        return $this->ok(Risk::monitor([
            'state' => $req->queryString('state', '', 24), 'flag' => $req->queryString('flag', '', 32), 'q' => $req->queryString('q', '', 60), 'page' => $req->queryInt('page', 1, 1, 100000),
        ]) + ['can' => $this->can($req)]);
    }

    /** GET /admin/api/payment-flags?status=open|closed|all&severity&type&page */
    public function flags(Request $req, array $p): Response
    {
        $this->authorize($req, 'payments.view');
        return $this->ok(Risk::flags([
            'status' => $req->queryString('status', 'open', 8), 'severity' => $req->queryString('severity', '', 8), 'type' => $req->queryString('type', '', 32), 'page' => $req->queryInt('page', 1, 1, 100000),
        ]) + ['counts' => Risk::openCounts(), 'can' => $this->can($req), 'types' => $this->types()]);
    }

    /** POST /admin/api/payment-flags/{id}/review  { status: reviewed|dismissed, note? } */
    public function review(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'payments.review');
        $id = $this->id($p);
        $in = $req->json();
        return $this->once($req, 'payment-flag-review', static fn(): array => [
            'flag' => Risk::review($id, (string) ($in['status'] ?? ''), (string) ($in['note'] ?? ''), $admin, $req), 'counts' => Risk::openCounts(),
        ]);
    }

    /** POST /admin/api/payments/{id}/recheck — ask the gateway about this payment now (rate-limited, audited). */
    public function recheck(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'payments.review');
        $id = $this->id($p);
        return $this->ok(Risk::recheck($id, $admin, $req) + ['counts' => Risk::openCounts()]);
    }

    /** GET /admin/api/payment-checks/settings */
    public function settings(Request $req, array $p): Response
    {
        $this->authorize($req, 'payments.view');
        return $this->ok(Risk::settingsView() + ['can' => $this->can($req)]);
    }

    /** PUT /admin/api/payment-checks/settings */
    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'alerts.manage');
        $in = $req->json();
        return $this->once($req, 'risk-settings', static fn(): array => Risk::saveSettings($in, $admin, $req));
    }

    /** @return array<string,array{severity:string,label:string,advice:string}> */
    private function types(): array
    {
        $out = [];
        foreach (Risk::TYPES as $type => [$severity, $label, $advice]) {
            $out[$type] = ['severity' => $severity, 'label' => $label, 'advice' => $advice];
        }
        return $out;
    }
}
