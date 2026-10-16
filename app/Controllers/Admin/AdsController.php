<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\AdSpend;
use App\Services\Ads;
use App\Services\AdsIntel;
use App\Services\Attribution;
use App\Services\Audit;
use App\Services\Finance;
use App\Support\Permissions;

/**
 * Ads Intelligence: campaign figures, the actual spend that replaces the CPC estimate, and the attribution settings.
 * Reading needs `ads.view` (owner, manager, analyst); entering spend and changing a setting needs `ads.manage` (owner, manager). Every change is audited.
 */
final class AdsController extends BaseController
{
    /** @return array<string,bool> */
    private function can(Request $req): array
    {
        return ['manage' => Permissions::can((string) $this->admin($req)['role'], 'ads.manage')];
    }

    /** GET /admin/api/ads?range=&from=&to=&campaign= */
    public function overview(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.view');
        $f = [];
        foreach (['range', 'from', 'to', 'campaign'] as $k) {
            $f[$k] = $req->queryString($k, '', 80);
        }
        if ($f['range'] === '') {
            $f['range'] = '30d';
        }
        return $this->ok(['ads' => AdsIntel::overview($f), 'can' => $this->can($req)]);
    }

    /** GET /admin/api/ads/spend?from=&to=&campaign=&include_voided=&page= */
    public function spend(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.view');
        return $this->ok(AdSpend::entries([
            'from' => $req->queryString('from', '', 10), 'to' => $req->queryString('to', '', 10), 'campaign' => $req->queryString('campaign', '', 80),
            'include_voided' => $req->queryString('include_voided') === '1', 'page' => $req->queryInt('page', 1, 1, 100000),
        ]) + ['campaigns' => AdSpend::campaigns(), 'can' => $this->can($req)]);
    }

    /** POST /admin/api/ads/spend  { day, campaign?, platform?, amount (₹), gst?, clicks?, impressions?, note? } — the same key replaces the earlier entry */
    public function addSpend(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'ads.manage');
        $in = $req->json();
        return $this->once($req, 'ads-spend', static fn(): array => ['entry' => AdSpend::save($in, $req, $admin)]);
    }

    /** POST /admin/api/ads/spend/{id}/void  { reason } */
    public function voidSpend(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'ads.manage');
        $id = $this->id($p);
        $in = $req->json();
        return $this->once($req, 'ads-spend-void', static fn(): array => ['entry' => AdSpend::void($id, (string) ($in['reason'] ?? ''), $req, $admin)]);
    }

    /** POST /admin/api/ads/spend/import  { csv, dry_run? } — a pasted report, validated line by line and saved all or nothing */
    public function importSpend(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'ads.manage');
        $in = $req->json();
        return $this->once($req, 'ads-spend-import', static fn(): array => ['result' => AdSpend::import((string) ($in['csv'] ?? ''), ($in['dry_run'] ?? false) === true, $req, $admin)]);
    }

    /** GET /admin/api/ads/settings */
    public function settings(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.view');
        return $this->ok($this->settingsPayload() + ['can' => $this->can($req)]);
    }

    /** PUT /admin/api/ads/settings  { window_days } */
    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'ads.manage');
        $in = $req->json();
        return $this->once($req, 'ads-settings', function () use ($in, $req, $admin): array {
            Attribution::saveSettings($in, $req, $admin);
            return $this->settingsPayload();
        });
    }

    /** POST /admin/api/ads/attribution/backfill  { dry_run? } — credit verified orders that have no attribution yet (older orders, or one that was missed) */
    public function backfill(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'ads.manage');
        $in = $req->json();
        $dry = ($in['dry_run'] ?? false) === true;
        return $this->once($req, 'ads-backfill', static function () use ($dry, $req, $admin): array {
            $n = Attribution::backfill(5000, 3650, $dry);
            if (!$dry && $n > 0) {
                Audit::log('ads.attribution_backfilled', 'ads', 'attribution', ['orders' => $n, 'model' => Attribution::MODEL, 'window_days' => Attribution::windowDays()], $req, $admin);
            }
            return ['result' => [
                'dry_run' => $dry, 'orders' => $n, 'remaining' => Attribution::missing(3650),
                'message' => $n === 0 ? 'Nothing to attribute.' : ($dry ? 'Would attribute ' . $n . ' verified order' . ($n === 1 ? '' : 's') . '. Nothing was changed.' : 'Attributed ' . $n . ' verified order' . ($n === 1 ? '' : 's') . '.'),
            ]];
        });
    }

    /** @return array<string,mixed> */
    private function settingsPayload(): array
    {
        return [
            'attribution' => ['model' => Attribution::MODEL, 'window_days' => Attribution::windowDays(), 'missing' => Attribution::missing(3650)],
            'cpc' => ['cpc_paise' => Ads::cpcPaise(), 'qualify_seconds' => Ads::current()['qualify_seconds']],
            'ad_gst' => Finance::settings()['ad_gst'],
        ];
    }
}
