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
    /** @var array<string,\Closure(array<string,mixed>,array<string,mixed>,array<string,mixed>):PaymentGateway> adapters of other providers, by provider key */
    private static array $factories = [];

    /**
     * Make another provider's adapter available. `$provider` is what payment_gateways.provider holds; the factory receives the gateway
     * row, its decoded config and its decrypted secrets. (Alpha UPI is built in.)
     *
     * @param \Closure(array<string,mixed>,array<string,mixed>,array<string,mixed>):PaymentGateway $factory
     */
    public static function register(string $provider, \Closure $factory): void
    {
        self::$factories[$provider] = $factory;
    }

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
        $config = is_array($config) ? $config : [];
        $secrets = Crypto::decryptJson($row['secrets_enc'] ?? null);
        if (isset(self::$factories[(string) $row['provider']])) {
            return (self::$factories[(string) $row['provider']])($row, $config, $secrets);
        }
        return match ($row['provider']) {
            AlphaUpiGateway::PROVIDER => new AlphaUpiGateway(['api_style' => self::style($row, $config, $secrets)] + $config, $secrets),
            default => throw new \RuntimeException('Unknown payment provider: ' . $row['provider']),
        };
    }

    /**
     * Which flavour of the Alpha UPI API this gateway uses: "headers" (X-API-Key + X-API-Secret — the current API) or "bearer" (one key —
     * the older SDK API). Chosen in the admin form; until then a gateway that is already live with just an API key keeps working as an
     * older setup, and every new or unfinished one uses the current API.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $config
     * @param array<string,mixed> $secrets
     */
    public static function style(array $row, array $config, array $secrets): string
    {
        $s = $config['api_style'] ?? null;
        if ($s === AlphaUpiGateway::STYLE_HEADERS || $s === AlphaUpiGateway::STYLE_BEARER) {
            return $s;
        }
        $liveWithKeyOnly = (int) ($row['is_active'] ?? 0) === 1 && !empty($secrets['api_key']) && empty($secrets['api_secret']);
        return $liveWithKeyOnly ? AlphaUpiGateway::STYLE_BEARER : AlphaUpiGateway::STYLE_HEADERS;
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
            'qr_from_checkout_page' => ($config['qr_from_checkout_page'] ?? true) !== false,
            'api_style'          => self::style($row, $config, $secrets),
            'has_api_key'        => !empty($secrets['api_key']),
            'api_key_hint'       => Crypto::mask($secrets['api_key'] ?? null),
            'has_api_secret'     => !empty($secrets['api_secret']),
            'api_secret_hint'    => Crypto::mask($secrets['api_secret'] ?? null),
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
                    $url = (string) preg_replace('#/+api/*$#i', '', rtrim($url, '/'));   // /api/... is appended by the client itself
                    $config['base_url'] = $url;
                    $changed[] = 'base_url';
                } catch (\InvalidArgumentException $e) {
                    $errors['base_url'] = $e->getMessage();
                }
            }
        }
        if (array_key_exists('api_style', $in)) {
            if (!in_array($in['api_style'], [AlphaUpiGateway::STYLE_HEADERS, AlphaUpiGateway::STYLE_BEARER], true)) {
                $errors['api_style'] = 'Choose the API type from the list.';
            } else {
                $config['api_style'] = (string) $in['api_style'];
                $changed[] = 'api_style';
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
        if (array_key_exists('qr_from_checkout_page', $in)) {
            $config['qr_from_checkout_page'] = (bool) $in['qr_from_checkout_page'];
            $changed[] = 'qr_from_checkout_page';
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
        foreach (['api_key' => 'API key', 'api_secret' => 'API secret', 'webhook_secret' => 'Webhook secret'] as $field => $label) {
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
            if (self::style($row, $config, $secrets) === AlphaUpiGateway::STYLE_HEADERS && empty($secrets['api_secret'])) {
                $errors['api_secret'] = 'An API secret is required to activate the gateway.';
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

    /**
     * "Check QR support": create a ₹1 test order at the gateway (it is never paid) and report what its checkout page carries, i.e. whether
     * customers will get a scannable QR on the payment page or be sent to the gateway's own checkout. Nothing is stored in our database
     * apart from the audit entry; the order stays unpaid at the gateway.
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array{ok:bool,found:bool,level:string,message:string,details:array<string,mixed>,checkout_url:?string,duration_ms:int}
     */
    public static function probeQr(int $id, Request $req, array $admin): array
    {
        $row = self::row($id) ?? throw new HttpException(404, 'Gateway not found.');
        $config = json_decode((string) ($row['config_json'] ?? ''), true);
        if (is_array($config) && ($config['qr_from_checkout_page'] ?? true) === false) {
            Audit::log('gateway.qr_checked', 'payment_gateway', $id, ['found' => false, 'reason' => 'lookup_off'], $req, $admin);
            return ['ok' => true, 'found' => false, 'level' => 'warning', 'details' => ['reason' => 'lookup_off'], 'checkout_url' => null, 'duration_ms' => 0,
                'message' => 'Showing the gateway’s QR on your payment page is switched off, so no test order was made. Customers are sent straight to the gateway’s own checkout page. Switch it on and check again to see whether its checkout page carries a UPI payment request.'];
        }
        $t0 = microtime(true);
        $res = self::adapter($row)->createPayment(new CreatePaymentRequest(
            gatewayOrderId: 'SMBQRCHECK' . strtoupper(bin2hex(random_bytes(6))),
            amountPaise: 100,
            redirectUrl: absolute_url('/'),
            remark1: 'QR support check',
            remark2: 'test order, never paid',
            customerName: 'QR check',
        ));
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $details = is_array($res->raw['qr_discovery'] ?? null) ? $res->raw['qr_discovery'] : [];
        $intent = $res->ok ? \App\Support\Upi::validateFor($res->upiIntent, 100) : null;

        if (!$res->ok) {
            $out = ['ok' => false, 'found' => false, 'level' => 'error', 'message' => 'The gateway did not create the ₹1 test order: ' . ($res->message !== '' ? $res->message : 'no reason given') . '.', 'details' => [], 'checkout_url' => null];
        } elseif ($intent !== null) {
            $out = ['ok' => true, 'found' => true, 'level' => 'ok', 'checkout_url' => $res->paymentUrl, 'details' => $details,
                'message' => 'Yes. The gateway’s checkout page carries a UPI payment request (payee ' . $intent['payee'] . ', ₹1.00), so customers see a scannable QR on your payment page and a UPI app opens straight on the payment screen. '
                    . 'The test order was never paid.'];
        } else {
            $why = (string) ($details['reason'] ?? 'not_checked');
            $facts = array_filter([
                isset($details['http']) ? 'HTTP ' . $details['http'] : null,
                isset($details['bytes']) ? round(((int) $details['bytes']) / 1024, 1) . ' KB' : null,
                isset($details['candidates']) ? $details['candidates'] . ' UPI link(s)' : null,
                isset($details['why']) ? (string) $details['why'] : null,
            ]);
            $out = ['ok' => true, 'found' => false, 'level' => 'warning', 'checkout_url' => $res->paymentUrl, 'details' => $details,
                'message' => 'The ₹1 test order was created, but no usable UPI payment request was found on its checkout page (' . str_replace('_', ' ', $why) . ($facts ? '; ' . implode(', ', $facts) : '') . '). '
                    . 'Customers are therefore sent straight to the gateway’s own checkout page, which shows its QR there. A QR on your own payment page needs a gateway whose API returns the UPI request — ask Alpha UPI support. The test order was never paid.'];
        }
        Audit::log('gateway.qr_checked', 'payment_gateway', $id, ['found' => $out['found'], 'reason' => (string) ($details['reason'] ?? ($res->ok ? 'unknown' : 'refused'))], $req, $admin);
        return $out + ['duration_ms' => $ms];
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
