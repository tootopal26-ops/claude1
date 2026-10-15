<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminSettings;
use App\Services\Ads;

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
        return $this->ok(['ads' => $this->adsView()]);
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
