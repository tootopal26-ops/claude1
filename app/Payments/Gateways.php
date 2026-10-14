<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Audit;
use App\Support\Clock;
use App\Support\NetGuard;

/**
 * Loads gateway configuration from the database and builds the adapter.
 * Credentials are stored AES-256-GCM encrypted and are never sent to a browser:
 * the admin UI only ever receives "set / not set" plus a masked tail.
 */
final class Gateways
{
    /** @return array<string,mixed>|null the active gateway row */
    public static function activeRow(): ?array
    {
        return Db::one('SELECT * FROM payment_gateways WHERE is_active = 1 ORDER BY id LIMIT 1');
    }

    public static function row(int $id): ?array
    {
        return Db::one('SELECT * FROM payment_gateways WHERE id = ?', [$id]);
    }

    public static function active(): ?PaymentGateway
    {
        $row = self::activeRow();
        return $row === null ? null : self::adapter($row);
    }

    /** @param array<string,mixed> $row */
    public static function adapter(array $row): PaymentGateway
    {
        $config = json_decode((string) ($row['config_json'] ?? ''), true);
        $secrets = Crypto::decryptJson($row['secrets_enc'] ?? null);
        return match ($row['provider']) {
            AlphaUpiGateway::PROVIDER => new AlphaUpiGateway(is_array($config) ? $config : [], $secrets),
            default => throw new \RuntimeException('Unknown payment provider: ' . $row['provider']),
        };
    }

    /** Shape for the admin form — never includes secrets. */
    public static function adminView(array $row): array
    {
        $config = json_decode((string) ($row['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $secrets = Crypto::decryptJson($row['secrets_enc'] ?? null);
        $lastWebhook = Db::one("SELECT received_at, status, signature_valid FROM webhook_events WHERE gateway_id = ? ORDER BY id DESC LIMIT 1", [$row['id']]);
        return [
            'id'                 => (int) $row['id'],
            'provider'           => $row['provider'],
            'name'               => $row['name'],
            'is_active'          => (bool) $row['is_active'],
            'environment'        => $row['environment'],
            'base_url'           => (string) ($config['base_url'] ?? ''),
            'provider_pref'      => (string) ($config['provider_pref'] ?? 'auto'),
            'has_api_key'        => !empty($secrets['api_key']),
            'api_key_hint'       => Crypto::mask($secrets['api_key'] ?? null),
            'has_webhook_secret' => !empty($secrets['webhook_secret']),
            'webhook_secret_hint' => Crypto::mask($secrets['webhook_secret'] ?? null),
            'webhook_url'        => absolute_url('/webhooks/payments/' . (int) $row['id']),
            'last_tested_at'     => Clock::iso($row['last_tested_at'] ?? null),
            'last_test_ok'       => $row['last_test_ok'] === null ? null : (bool) $row['last_test_ok'],
            'last_test_message'  => $row['last_test_message'],
            'last_webhook'       => $lastWebhook ? [
                'at' => Clock::iso($lastWebhook['received_at']), 'status' => $lastWebhook['status'], 'signature_valid' => (bool) $lastWebhook['signature_valid'],
            ] : null,
            'supports_refunds'   => false,
        ];
    }

    /**
     * Create/update the gateway. Blank secret fields keep the stored value.
     *
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string} $admin
     */
    public static function save(int $id, array $in, Request $req, array $admin): array
    {
        $row = self::row($id) ?? throw new HttpException(404, 'Gateway not found.');
        $config = json_decode((string) ($row['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $secrets = Crypto::decryptJson($row['secrets_enc'] ?? null);
        $errors = [];

        $changed = [];
        if (array_key_exists('base_url', $in)) {
            $url = trim((string) $in['base_url']);
            if ($url === '') {
                $errors['base_url'] = 'Enter the gateway base URL.';
            } else {
                try {
                    $url = NetGuard::assertSafeUrl($url, 'gateway URL');
                    $url = (string) preg_replace('#/api$#i', '', $url);   // the SDK appends /api/... itself
                    $config['base_url'] = $url;
                    $changed[] = 'base_url';
                } catch (\InvalidArgumentException $e) {
                    $errors['base_url'] = $e->getMessage();
                }
            }
        }
        if (array_key_exists('provider_pref', $in)) {
            $pref = (string) $in['provider_pref'];
            if (!in_array($pref, ['auto', 'bharatpe', 'paytm'], true)) {
                $errors['provider_pref'] = 'Choose auto, BharatPe or Paytm.';
            } else {
                $config['provider_pref'] = $pref;
                $changed[] = 'provider_pref';
            }
        }
        $env = $row['environment'];
        if (array_key_exists('environment', $in)) {
            if (!in_array($in['environment'], ['test', 'production'], true)) {
                $errors['environment'] = 'Choose test or production.';
            } else {
                $env = (string) $in['environment'];
                $changed[] = 'environment';
            }
        }
        foreach (['api_key' => 'API key', 'webhook_secret' => 'Webhook secret'] as $field => $label) {
            $v = isset($in[$field]) && is_string($in[$field]) ? trim($in[$field]) : '';
            if ($v !== '') {
                if (strlen($v) < 8 || strlen($v) > 500 || preg_match('/\s/', $v)) {
                    $errors[$field] = "{$label} looks invalid (no spaces, 8–500 characters).";
                } else {
                    $secrets[$field] = $v;
                    $changed[] = $field;
                }
            }
        }

        $wantActive = array_key_exists('is_active', $in) ? (bool) $in['is_active'] : (bool) $row['is_active'];
        if ($wantActive) {
            if (empty($config['base_url'])) {
                $errors['base_url'] = 'A base URL is required to activate the gateway.';
            }
            if (empty($secrets['api_key'])) {
                $errors['api_key'] = 'An API key is required to activate the gateway.';
            }
            if (empty($secrets['webhook_secret'])) {
                $errors['webhook_secret'] = 'A webhook secret is required so payments can be confirmed securely.';
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }

        Db::tx(static function () use ($id, $config, $secrets, $env, $wantActive): void {
            if ($wantActive) {
                Db::exec('UPDATE payment_gateways SET is_active = 0 WHERE id <> ?', [$id]);
            }
            Db::update('payment_gateways', [
                'config_json' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'secrets_enc' => Crypto::encryptJson($secrets),
                'environment' => $env,
                'is_active'   => $wantActive ? 1 : 0,
                'updated_at'  => Clock::sql(),
            ], 'id = ?', [$id]);
        });
        if ($wantActive !== (bool) $row['is_active']) {
            $changed[] = $wantActive ? 'activated' : 'deactivated';
        }
        // Field NAMES only — never values.
        Audit::log('gateway.saved', 'payment_gateway', $id, ['changed' => $changed], $req, $admin);
        return self::adminView(self::row($id));
    }

    /** @return array{ok:bool,message:string,level:string,duration_ms:int} */
    public static function test(int $id, Request $req, array $admin): array
    {
        $row = self::row($id) ?? throw new HttpException(404, 'Gateway not found.');
        $result = self::adapter($row)->testConnection();
        Db::update('payment_gateways', [
            'last_tested_at' => Clock::sql(), 'last_test_ok' => $result->ok ? 1 : 0, 'last_test_message' => mb_substr($result->message, 0, 255),
        ], 'id = ?', [$id]);
        Audit::log('gateway.tested', 'payment_gateway', $id, ['ok' => $result->ok, 'level' => $result->level], $req, $admin);
        return ['ok' => $result->ok, 'message' => $result->message, 'level' => $result->level, 'duration_ms' => $result->durationMs];
    }
}
