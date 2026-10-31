<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdBudget;
use App\Services\AdminSettings;
use App\Services\Ads;
use App\Services\SalesTarget;

final class SettingsController extends BaseController
{
    public function website(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        return $this->ok(['website' => AdminSettings::website()]);
    }

    public function saveWebsite(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'settings-website', static fn(): array => ['website' => AdminSettings::saveWebsite($in, $req, $admin)]);
    }

    public function appearance(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        return $this->ok(['appearance' => AdminSettings::appearance()]);
    }

    public function saveAppearance(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'settings-appearance', static fn(): array => ['appearance' => AdminSettings::saveAppearance($in, $req, $admin)]);
    }

    public function resetAppearance(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        $admin = $this->admin($req);
        return $this->once($req, 'settings-appearance', static fn(): array => ['appearance' => AdminSettings::resetAppearance($req, $admin)]);
    }

    public function revertAppearance(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        $admin = $this->admin($req);
        return $this->once($req, 'settings-appearance', static fn(): array => ['appearance' => AdminSettings::revertAppearance($req, $admin)]);
    }

    /** POST /admin/api/branding/{kind:site_logo|favicon} (multipart) */
    public function uploadBranding(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        $file = $req->files['file'] ?? null;
        if (!is_array($file)) {
            throw new HttpException(422, 'Choose an image to upload.', 'upload_failed');
        }
        return $this->ok(AdminSettings::uploadBranding((string) $p['kind'], $file, $req, $this->admin($req)));
    }

    public function resetBranding(Request $req, array $p): Response
    {
        $this->authorize($req, 'catalog.manage');
        return $this->ok(AdminSettings::resetBranding((string) $p['kind'], $req, $this->admin($req)));
    }

    // ── advertising ──

    public function advertising(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.view');
        return $this->ok(['ads' => $this->adsView(), 'sales_target' => SalesTarget::progress(), 'ad_budget' => $this->adBudgetView()]);
    }

    public function saveAdvertising(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.manage');
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'settings-ads', function () use ($in, $req, $admin): array {
            Ads::update($in['cpc'] ?? null, $in['qualify_seconds'] ?? null, $in['assumed_conversion'] ?? null, $req, $admin);
            return ['ads' => $this->adsView()];
        });
    }

    public function saveSalesTarget(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.manage');
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'settings-sales-target', fn(): array => ['sales_target' => SalesTarget::set($in, $req, $admin)]);
    }

    public function revokeSalesTarget(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.manage');
        $admin = $this->admin($req);
        return $this->once($req, 'settings-sales-target-revoke', fn(): array => ['sales_target' => SalesTarget::revoke($req, $admin)]);
    }

    public function salesTargetHistory(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.view');
        return $this->ok(['history' => SalesTarget::history()]);
    }

    public function saveAdBudget(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.manage');
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'settings-ad-budget', function () use ($in, $req, $admin): array {
            AdBudget::save($in, $req, $admin);
            return ['ad_budget' => $this->adBudgetView()];
        });
    }

    public function resetAdBudget(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.manage');
        $admin = $this->admin($req);
        return $this->once($req, 'settings-ad-budget-reset', function () use ($req, $admin): array {
            AdBudget::reset($req, $admin);
            return ['ad_budget' => $this->adBudgetView()];
        });
    }

    public function revokeAdBudgetOverride(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.manage');
        $admin = $this->admin($req);
        return $this->once($req, 'settings-ad-budget-revoke', function () use ($req, $admin): array {
            AdBudget::revokeOverride($req, $admin);
            return ['ad_budget' => $this->adBudgetView()];
        });
    }

    public function adBudgetHistory(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.view');
        return $this->ok(['history' => AdBudget::history()]);
    }

    /** POST /admin/api/ad-budget/forecast  { budget, campaign? } — no write */
    public function forecastAdBudget(Request $req, array $p): Response
    {
        $this->authorize($req, 'ads.view');
        $in = $req->json();
        $raw = $in['budget'] ?? null;
        try {
            $budget = \App\Support\Money::parseRupees(is_string($raw) || is_int($raw) || is_float($raw) ? $raw : null);
        } catch (\InvalidArgumentException) {
            throw new HttpException(422, 'Enter the budget in rupees with at most two decimals.', 'invalid', ['fields' => ['budget' => 'Enter the budget in rupees with at most two decimals.']]);
        }
        $campaign = is_string($in['campaign'] ?? null) ? trim($in['campaign']) : '';
        return $this->ok(['forecast' => AdBudget::forecast($budget, $campaign)]);
    }

    /** @return array<string,mixed> */
    private function adBudgetView(): array
    {
        $c = AdBudget::current();
        return $c + [
            'daily_budget' => $c['daily_budget_paise'] !== null ? \App\Support\Money::plain($c['daily_budget_paise']) : null,
            'monthly_budget' => $c['monthly_budget_paise'] !== null ? \App\Support\Money::plain($c['monthly_budget_paise']) : null,
            'history' => AdBudget::history(),
        ];
    }

    /** @return array<string,mixed> */
    private function adsView(): array
    {
        $c = Ads::current();
        $est = Ads::estimatedPerOrder();
        return [
            'cpc' => \App\Support\Money::plain($c['cpc_paise']), 'cpc_paise' => $c['cpc_paise'], 'qualify_seconds' => $c['qualify_seconds'],
            'assumed_conversion' => \App\Support\Money::tenthsToString((int) round($c['assumed_conversion_bps'] / 10)),
            'history' => Ads::history(), 'est_per_order_paise' => $est['paise'], 'est_basis' => $est['basis'],
        ];
    }
}
