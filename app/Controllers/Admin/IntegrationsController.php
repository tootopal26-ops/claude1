<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Payments\Gateways;
use App\Services\Audit;
use App\Services\SmmProviders;

/** Payment gateway and SMM provider configuration. Secrets are write-only: they are never sent back to the browser. */
final class IntegrationsController extends BaseController
{
    // ── payment gateway ──

    public function gateway(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $row = Db::one('SELECT * FROM payment_gateways ORDER BY is_active DESC, id LIMIT 1');
        if ($row === null) {
            throw new HttpException(404, 'No payment gateway is installed.');
        }
        return $this->ok(['gateway' => Gateways::adminView($row)]);
    }

    public function saveGateway(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $id = $this->id($p);
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'gateway-save', static fn(): array => ['gateway' => Gateways::save($id, $in, $req, $admin)]);
    }

    public function testGateway(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $id = $this->id($p);
        RateLimiter::enforce('admin-test-gateway', (string) $this->admin($req)['id'], 12, 300, 'Too many connection tests. Please wait a moment.');
        return $this->ok(['result' => Gateways::test($id, $req, $this->admin($req)), 'gateway' => Gateways::adminView(Gateways::row($id))]);
    }

    /** Creates a ₹1 test order at the gateway (never paid) to see whether its checkout page exposes the UPI request behind its QR. */
    public function qrCheckGateway(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $id = $this->id($p);
        $admin = $this->admin($req);
        RateLimiter::enforce('admin-qr-check', (string) $admin['id'], 6, 600, 'Too many QR checks. Please wait a few minutes.');
        return $this->once($req, 'gateway-qr-check', static fn(): array => ['result' => Gateways::probeQr($id, $req, $admin)]);
    }

    // ── SMM providers ──

    public function providers(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        return $this->ok(['providers' => SmmProviders::all()]);
    }

    public function createProvider(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'smm-save', static fn(): array => ['provider' => SmmProviders::save(null, $in, $req, $admin), 'providers' => SmmProviders::all()]);
    }

    public function updateProvider(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $id = $this->id($p);
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'smm-save', static fn(): array => ['provider' => SmmProviders::save($id, $in, $req, $admin), 'providers' => SmmProviders::all()]);
    }

    public function testProvider(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $id = $this->id($p);
        RateLimiter::enforce('admin-test-smm', (string) $this->admin($req)['id'], 12, 300, 'Too many connection tests. Please wait a moment.');
        $result = SmmProviders::test($id, $req, $this->admin($req));
        return $this->ok(['result' => $result, 'providers' => SmmProviders::all()]);
    }

    public function providerServices(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $id = $this->id($p);
        RateLimiter::enforce('admin-smm-services', (string) $this->admin($req)['id'], 30, 300, 'Too many lookups. Please wait a moment.');
        return $this->ok(['result' => SmmProviders::searchServices($id, $req->queryString('q', '', 80))]);
    }

    public function deleteProvider(Request $req, array $p): Response
    {
        $this->authorize($req, 'integrations.manage');
        $id = $this->id($p);
        $row = SmmProviders::row($id) ?? throw new HttpException(404, 'Provider not found.');
        $using = (int) Db::val('SELECT COUNT(*) FROM services WHERE smm_provider_id = ? AND deleted_at IS NULL', [$id]);
        if ($using > 0) {
            throw new HttpException(409, "{$using} service(s) still use this provider. Switch them to another provider (or MANUAL) first, or deactivate the provider instead.", 'in_use');
        }
        $hasOrders = (int) Db::val('SELECT COUNT(*) FROM orders WHERE smm_provider_id = ?', [$id]) > 0;
        if ($hasOrders) {
            // keep the row so past orders still show where they were fulfilled — just switch it off
            Db::update('smm_providers', ['is_active' => 0, 'is_default' => 0, 'updated_at' => \App\Support\Clock::sql()], 'id = ?', [$id]);
            Audit::log('smm.provider_saved', 'smm_provider', $id, ['deactivated' => true], $req, $this->admin($req));
            return $this->ok(['message' => 'The provider has past orders, so it was deactivated instead of deleted.', 'providers' => SmmProviders::all()]);
        }
        Db::exec('DELETE FROM smm_providers WHERE id = ?', [$id]);
        if ((bool) $row['is_default']) {
            Db::exec('UPDATE smm_providers SET is_default = 1 WHERE is_active = 1 ORDER BY id LIMIT 1');
        }
        Audit::log('smm.provider_saved', 'smm_provider', $id, ['deleted' => true], $req, $this->admin($req));
        return $this->ok(['message' => 'Provider removed.', 'providers' => SmmProviders::all()]);
    }
}
