<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiService;

/**
 * The one optional AI provider (Admin → AI & Support → Provider). Everything here needs `ai.configure` — owner only, since
 * switching this on decides whether any data leaves the server for a third party at all.
 */
final class AiController extends BaseController
{
    public function settings(Request $req, array $p): Response
    {
        $this->authorize($req, 'ai.configure');
        return $this->ok(AiService::settings());
    }

    /** PUT /admin/api/ai/settings { enabled?, provider?, model?, base_url?, api_key?, clear_key?, use_for_reports?, use_for_support? } */
    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'ai.configure');
        $in = $req->json();
        return $this->once($req, 'ai-settings', static fn(): array => AiService::saveSettings($in, $admin, $req));
    }

    /** POST /admin/api/ai/test — a trivial round trip against the SAVED settings; 5 per 10 minutes per admin. */
    public function test(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'ai.configure');
        RateLimiter::enforce('ai-test', (string) $admin['id'], 5, 600, 'Too many test calls. Wait a few minutes.');
        return $this->ok(['result' => AiService::testConnection($admin, $req)]);
    }

    public function usage(Request $req, array $p): Response
    {
        $this->authorize($req, 'ai.configure');
        return $this->ok(AiService::usage($req->queryInt('days', 30, 1, 90)));
    }
}
