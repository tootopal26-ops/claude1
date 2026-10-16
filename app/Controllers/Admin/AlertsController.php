<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\Alerts;
use App\Services\Watchdog;
use App\Support\Permissions;

/**
 * Business Watchdog alerts: the list, what each health check last saw, the thresholds, and the optional e-mail.
 * Reading needs `alerts.view` (owner, manager, analyst, support); acknowledging, resolving and changing a threshold or the e-mail needs
 * `alerts.manage` (owner, manager). Running the checks on demand is the generic jobs endpoint (`jobs.run`). Every change is audited.
 */
final class AlertsController extends BaseController
{
    /** @return array<string,bool> */
    private function can(Request $req): array
    {
        $role = (string) $this->admin($req)['role'];
        return ['manage' => Permissions::can($role, 'alerts.manage'), 'run' => Permissions::can($role, 'jobs.run')];
    }

    /** GET /admin/api/alerts?status=active|resolved|all&severity&type&page */
    public function list(Request $req, array $p): Response
    {
        $this->authorize($req, 'alerts.view');
        $list = Alerts::list([
            'status' => $req->queryString('status', 'active', 10), 'severity' => $req->queryString('severity', '', 10),
            'type' => $req->queryString('type', '', 40), 'page' => $req->queryInt('page', 1, 1, 100000),
        ]);
        return $this->ok($list + ['counts' => Alerts::counts(), 'types' => array_map(static fn(array $c): string => $c[0], Watchdog::CHECKS) + ['payment_flag' => 'Payment checks'], 'can' => $this->can($req)]);
    }

    /** GET /admin/api/alerts/checks — each check, what it last saw, and the watchdog job's last run. */
    public function checks(Request $req, array $p): Response
    {
        $this->authorize($req, 'alerts.view');
        return $this->ok(['watchdog' => Watchdog::overview(), 'can' => $this->can($req)]);
    }

    /** POST /admin/api/alerts/{id}/acknowledge */
    public function acknowledge(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'alerts.manage');
        $id = $this->id($p);
        return $this->once($req, 'alert-ack', static function () use ($id, $admin, $req): array {
            Alerts::acknowledge($id, $admin, $req);
            return ['counts' => Alerts::counts()];
        });
    }

    /** POST /admin/api/alerts/{id}/resolve */
    public function resolve(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'alerts.manage');
        $id = $this->id($p);
        return $this->once($req, 'alert-resolve', static function () use ($id, $admin, $req): array {
            Alerts::resolve($id, $admin, $req);
            return ['counts' => Alerts::counts()];
        });
    }

    /** GET /admin/api/alerts/settings — thresholds, switches and e-mail settings (recipients only for those who may change them). */
    public function settings(Request $req, array $p): Response
    {
        $this->authorize($req, 'alerts.view');
        return $this->ok(Watchdog::settingsView() + ['mail' => $this->mailView($req), 'can' => $this->can($req)]);
    }

    /** PUT /admin/api/alerts/settings  { thresholds: {name: int}, enabled: {check: bool} } */
    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'alerts.manage');
        $in = $req->json();
        return $this->once($req, 'alert-settings', static fn(): array => Watchdog::saveSettings($in, $admin, $req));
    }

    /** PUT /admin/api/alerts/mail  { enabled, to, from, min_severity, cooldown_min } */
    public function saveMail(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'alerts.manage');
        $in = $req->json();
        return $this->once($req, 'alert-mail', function () use ($in, $admin, $req): array {
            Alerts::saveMailSettings($in, $admin, $req);
            return ['mail' => $this->mailView($req)];
        });
    }

    /** POST /admin/api/alerts/mail/test — one message to the saved recipients; 5 per 10 minutes per admin. */
    public function testMail(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'alerts.manage');
        RateLimiter::enforce('alert-test-mail', (string) $admin['id'], 5, 600, 'Too many test e-mails. Wait a few minutes.');
        return $this->ok(['result' => Alerts::sendTest($admin, $req)]);
    }

    /** @return array<string,mixed> the e-mail settings; recipients and sender are shown only to someone who may change them */
    private function mailView(Request $req): array
    {
        $m = Alerts::mailSettings();
        $recipients = array_values(array_filter(array_map('trim', explode(',', $m['to']))));
        if (!$this->can($req)['manage']) {
            return ['enabled' => $m['enabled'], 'recipients' => count($recipients), 'min_severity' => $m['min_severity'], 'cooldown_min' => $m['cooldown_min']];
        }
        return $m + ['recipients' => count($recipients)];
    }
}
