<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\AiService;
use App\Services\SupportAssistant;
use App\Support\Permissions;

/**
 * Support Assistant monitoring and configuration (master prompt §13). Reading conversations needs `support.view` (owner,
 * manager, support — the roles that already help customers); switching the assistant on/off and its retention need
 * `support.configure` (owner, manager). Whether AI may be used for it at all is an AI-provider decision (`ai.configure`,
 * owner only) made on the AI & Support → Provider tab, not here.
 */
final class SupportController extends BaseController
{
    /** @return array<string,bool> */
    private function can(Request $req): array
    {
        $role = (string) $this->admin($req)['role'];
        return ['configure' => Permissions::can($role, 'support.configure')];
    }

    public function overview(Request $req, array $p): Response
    {
        $this->authorize($req, 'support.view');
        $ai = AiService::settings();
        return $this->ok([
            'enabled' => SupportAssistant::enabled(), 'retention_days' => SupportAssistant::retentionDays(), 'ai_for_support' => $ai['available'] && $ai['use_for_support'],
            'can' => $this->can($req),
        ] + SupportAssistant::adminList('', 1, 5));
    }

    /** GET /admin/api/support/conversations?status=&page= */
    public function conversations(Request $req, array $p): Response
    {
        $this->authorize($req, 'support.view');
        $status = $req->queryString('status', '', 10);
        return $this->ok(SupportAssistant::adminList($status, $req->queryInt('page', 1, 1, 100000)) + ['can' => $this->can($req)]);
    }

    public function conversation(Request $req, array $p): Response
    {
        $this->authorize($req, 'support.view');
        return $this->ok(['conversation' => SupportAssistant::adminConversation($this->id($p))]);
    }

    /** PUT /admin/api/support/settings { enabled?, retention_days? } */
    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'support.configure');
        $in = $req->json();
        return $this->once($req, 'support-settings', static fn(): array => SupportAssistant::saveSettings($in, $admin, $req));
    }
}
