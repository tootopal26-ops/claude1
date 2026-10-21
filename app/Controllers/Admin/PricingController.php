<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\Pricing;
use App\Services\PricingAi;
use App\Support\Permissions;

/**
 * Adaptive pricing (master prompt, Adaptive Pricing & AI Sales Optimization Engine): the experiment dashboard, the Price
 * Experiment Simulator, Profit Guard and the AI Learning History — all of it backed by App\Services\Pricing, never a mock.
 *
 * Permissions: `pricing.view` sees everything here; `pricing.propose` creates an experiment and may start it as a dry run (an
 * A/A test — no customer ever sees a different price); `pricing.approve` is the ONLY permission that can switch an experiment
 * to live or activate a winning candidate as the new default price (owner only); `pricing.pause` pauses, resumes, rolls back,
 * and operates the global/per-service pause switches (Profit Guard).
 */
final class PricingController extends BaseController
{
    /** @return array<string,bool> */
    private function can(Request $req): array
    {
        $role = (string) $this->admin($req)['role'];
        return [
            'propose' => Permissions::can($role, 'pricing.propose'),
            'approve' => Permissions::can($role, 'pricing.approve'),
            'pause' => Permissions::can($role, 'pricing.pause'),
        ];
    }

    public function settings(Request $req, array $p): Response
    {
        $this->authorize($req, 'pricing.view');
        return $this->ok(['settings' => Pricing::settings(), 'can' => $this->can($req)]);
    }

    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.approve');
        $in = $req->json();
        return $this->once($req, 'pricing-settings', static fn(): array => ['settings' => Pricing::saveSettings($in, $req, $admin)]);
    }

    public function services(Request $req, array $p): Response
    {
        $this->authorize($req, 'pricing.view');
        return $this->ok(['rows' => Pricing::eligibleServices(), 'can' => $this->can($req)]);
    }

    /** PUT /admin/api/pricing/services/{id}  { adaptive_enabled?, min_price?, max_price?, pricing_paused? } */
    public function saveService(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.pause');
        $in = $req->json();
        return $this->once($req, 'pricing-service', fn(): array => ['service' => Pricing::setServiceAdaptive($this->id($p), $in, $req, $admin)]);
    }

    /** POST /admin/api/pricing/simulate/{id}  { candidates: ["49", "99", ...] } — no write, any eligible or inspectable MANUAL service */
    public function simulate(Request $req, array $p): Response
    {
        $this->authorize($req, 'pricing.view');
        $in = $req->json();
        $candidates = is_array($in['candidates'] ?? null) ? array_slice($in['candidates'], 0, 8) : [];
        return $this->ok(['simulation' => Pricing::simulate($this->id($p), $candidates)]);
    }

    /** GET /admin/api/pricing/experiments?status=&page= */
    public function experiments(Request $req, array $p): Response
    {
        $this->authorize($req, 'pricing.view');
        return $this->ok(Pricing::experiments(['status' => $req->queryString('status', '', 12), 'page' => $req->queryInt('page', 1, 1, 100000)]) + ['can' => $this->can($req)]);
    }

    public function experiment(Request $req, array $p): Response
    {
        $this->authorize($req, 'pricing.view');
        return $this->ok(['experiment' => Pricing::experiment($this->id($p)), 'can' => $this->can($req)]);
    }

    /** POST /admin/api/pricing/experiments  { service_id, candidate_price, treatment_share_pct?, min_sample?, max_duration_days?, profit_floor? } */
    public function propose(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.propose');
        $in = $req->json();
        $serviceId = (int) ($in['service_id'] ?? 0);
        return $this->once($req, 'pricing-propose', fn(): array => ['experiment' => Pricing::propose($serviceId, $in, $req, $admin)]);
    }

    /** POST /admin/api/pricing/experiments/{id}/start  { mode: "dry_run"|"live" } — "live" needs pricing.approve */
    public function start(Request $req, array $p): Response
    {
        $in = $req->json();
        $mode = (string) ($in['mode'] ?? 'dry_run');
        $admin = $this->authorize($req, $mode === 'live' ? 'pricing.approve' : 'pricing.propose');
        return $this->once($req, 'pricing-start', fn(): array => ['experiment' => Pricing::start($this->id($p), $mode, $req, $admin)]);
    }

    public function goLive(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.approve');
        return $this->once($req, 'pricing-golive', fn(): array => ['experiment' => Pricing::goLive($this->id($p), $req, $admin)]);
    }

    public function pause(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.pause');
        $reason = trim((string) ($req->json()['reason'] ?? ''));
        return $this->once($req, 'pricing-pause', fn(): array => ['experiment' => Pricing::pause($this->id($p), $reason, $req, $admin)]);
    }

    public function resume(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.pause');
        return $this->once($req, 'pricing-resume', fn(): array => ['experiment' => Pricing::resume($this->id($p), $req, $admin)]);
    }

    public function rollback(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.pause');
        $reason = trim((string) ($req->json()['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Rolled back by an admin.';
        }
        return $this->once($req, 'pricing-rollback', fn(): array => ['experiment' => Pricing::rollback($this->id($p), $reason, $req, $admin)]);
    }

    public function applyWinner(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.approve');
        return $this->once($req, 'pricing-apply', fn(): array => ['experiment' => Pricing::applyWinner($this->id($p), $req, $admin)]);
    }

    public function cancel(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.pause');
        $reason = trim((string) ($req->json()['reason'] ?? ''));
        return $this->once($req, 'pricing-cancel', fn(): array => ['experiment' => Pricing::cancel($this->id($p), $reason, $req, $admin)]);
    }

    /** POST /admin/api/pricing/experiments/{id}/evaluate — run the evaluator for one experiment right now (the same logic as the cron job). */
    public function evaluateNow(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.pause');
        RateLimiter::enforce('pricing-evaluate', (string) $admin['id'], 30, 600, 'Too many manual evaluations. Wait a few minutes.');
        $id = $this->id($p);
        $result = Pricing::evaluate($id, true);
        return $this->ok(['result' => $result, 'experiment' => Pricing::experiment($id)]);
    }

    public function profitGuard(Request $req, array $p): Response
    {
        $this->authorize($req, 'pricing.view');
        return $this->ok(['guard' => Pricing::profitGuard(), 'can' => $this->can($req)]);
    }

    public function globalPause(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.pause');
        $paused = ($req->json()['paused'] ?? null) === true;
        return $this->once($req, 'pricing-global-pause', static fn(): array => ['settings' => Pricing::setGlobalPause($paused, $req, $admin)]);
    }

    public function learningHistory(Request $req, array $p): Response
    {
        $this->authorize($req, 'pricing.view');
        return $this->ok(Pricing::learningHistory(['page' => $req->queryInt('page', 1, 1, 100000)]));
    }

    /** POST /admin/api/pricing/experiments/{id}/ai-insight — advisory only; 10 per 10 minutes per admin. */
    public function aiInsight(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'pricing.view');
        RateLimiter::enforce('pricing-ai', (string) $admin['id'], 10, 600, 'Too many AI requests. Wait a few minutes.');
        $id = $this->id($p);
        $r = PricingAi::explain($id, $admin, $req);
        return $this->ok(['result' => $r]);
    }
}
