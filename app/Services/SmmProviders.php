<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Smm\SmmClient;
use App\Smm\SmmResponse;
use App\Support\Clock;
use App\Support\Money;
use App\Support\NetGuard;

/**
 * SMM provider records (API URL + encrypted API key) and the admin-facing
 * operations on them. The API key is stored AES-256-GCM encrypted and is only
 * ever decrypted server-side, immediately before a call.
 */
final class SmmProviders
{
    public static function row(int $id): ?array
    {
        return Db::one('SELECT * FROM smm_providers WHERE id = ?', [$id]);
    }

    public static function defaultRow(): ?array
    {
        return Db::one('SELECT * FROM smm_providers WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
    }

    public static function client(array $row): SmmClient
    {
        return new SmmClient((string) $row['api_url'], (string) (Crypto::decrypt($row['api_key_enc'] ?? null) ?? ''));
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return array_map([self::class, 'adminView'], Db::all('SELECT * FROM smm_providers ORDER BY is_default DESC, id'));
    }

    /** Admin form shape — the key itself is never included. */
    public static function adminView(array $r): array
    {
        $key = Crypto::decrypt($r['api_key_enc'] ?? null);
        $rate = (int) $r['rate_to_inr_micros'];
        $services = (int) Db::val('SELECT COUNT(*) FROM services WHERE smm_provider_id = ? AND deleted_at IS NULL', [$r['id']]);
        return [
            'id'              => (int) $r['id'],
            'name'            => (string) $r['name'],
            'api_url'         => (string) $r['api_url'],
            'has_api_key'     => $key !== null && $key !== '',
            'api_key_hint'    => Crypto::mask($key),
            'currency'        => (string) $r['currency'],
            'rate_to_inr'     => rtrim(rtrim(sprintf('%d.%06d', intdiv($rate, 1_000_000), $rate % 1_000_000), '0'), '.'),
            'is_active'       => (bool) $r['is_active'],
            'is_default'      => (bool) $r['is_default'],
            'last_tested_at'  => Clock::iso($r['last_tested_at']),
            'last_test_ok'    => $r['last_test_ok'] === null ? null : (bool) $r['last_test_ok'],
            'last_test_message' => $r['last_test_message'],
            'last_balance'    => $r['last_balance'],
            'services_using'  => $services,
        ];
    }

    /**
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string} $admin
     */
    public static function save(?int $id, array $in, Request $req, array $admin): array
    {
        $row = $id !== null ? (self::row($id) ?? throw new HttpException(404, 'Provider not found.')) : null;
        $errors = [];
        $data = [];

        $name = trim((string) ($in['name'] ?? ($row['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Enter a name (up to 80 characters).';
        }
        $data['name'] = $name;

        $url = trim((string) ($in['api_url'] ?? ($row['api_url'] ?? '')));
        try {
            $data['api_url'] = NetGuard::assertSafeUrl($url, 'API URL');
        } catch (\InvalidArgumentException $e) {
            $errors['api_url'] = $e->getMessage();
        }

        $currency = strtoupper(trim((string) ($in['currency'] ?? ($row['currency'] ?? 'INR'))));
        if (!preg_match('/^[A-Z]{3,8}$/', $currency)) {
            $errors['currency'] = 'Use a currency code such as INR or USD.';
        }
        $data['currency'] = $currency;

        $rateIn = $currency === 'INR' ? '1' : trim((string) ($in['rate_to_inr'] ?? ''));
        if ($currency !== 'INR' && $rateIn === '' && $row !== null) {
            $data['rate_to_inr_micros'] = (int) $row['rate_to_inr_micros'];
        } else {
            $micros = Money::parseDecimalMicros($rateIn);
            if ($micros === null || $micros <= 0 || $micros > 1_000_000_000_000) {
                $errors['rate_to_inr'] = 'Enter how many rupees one ' . $currency . ' is worth (for example 83.5).';
            } else {
                $data['rate_to_inr_micros'] = $micros;
            }
        }

        $newKey = isset($in['api_key']) && is_string($in['api_key']) ? trim($in['api_key']) : '';
        if ($newKey !== '') {
            if (strlen($newKey) < 6 || strlen($newKey) > 300 || preg_match('/\s/', $newKey)) {
                $errors['api_key'] = 'The API key looks invalid (no spaces, 6–300 characters).';
            } else {
                $data['api_key_enc'] = Crypto::encrypt($newKey);
            }
        } elseif ($row === null || empty($row['api_key_enc'])) {
            $errors['api_key'] = 'Enter the provider API key.';
        }

        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }

        $data['is_active'] = array_key_exists('is_active', $in) ? (int) (bool) $in['is_active'] : (int) ($row['is_active'] ?? 1);
        $makeDefault = array_key_exists('is_default', $in) ? (bool) $in['is_default'] : (bool) ($row['is_default'] ?? false);
        $now = Clock::sql();

        $savedId = Db::tx(static function () use ($id, $data, $makeDefault, $now, $row): int {
            if ($row === null) {
                $first = (int) Db::val('SELECT COUNT(*) FROM smm_providers') === 0;
                $makeDefault = $makeDefault || $first;
                $newId = Db::insert('smm_providers', $data + ['is_default' => 0, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $newId = (int) $id;
                Db::update('smm_providers', $data + ['updated_at' => $now], 'id = ?', [$newId]);
            }
            if ($makeDefault) {
                Db::exec('UPDATE smm_providers SET is_default = (id = ?)', [$newId]);
            } elseif ($row !== null && (bool) $row['is_default'] && !$makeDefault) {
                Db::exec('UPDATE smm_providers SET is_default = 0 WHERE id = ?', [$newId]);
            }
            return $newId;
        });

        // Names of fields that changed — never their values.
        $changed = array_values(array_filter(array_keys($data), static fn(string $k): bool => $k === 'api_key_enc' || $row === null || (string) ($row[$k] ?? '') !== (string) $data[$k]));
        Audit::log('smm.provider_saved', 'smm_provider', $savedId, ['created' => $row === null, 'changed' => array_map(static fn(string $k): string => $k === 'api_key_enc' ? 'api_key' : $k, $changed)], $req, $admin);
        return self::adminView(self::row($savedId));
    }

    /**
     * Test connection = ask the provider for the account balance.
     *
     * @return array{ok:bool,message:string,balance:?string,duration_ms:int,level:string}
     */
    public static function test(int $id, Request $req, array $admin): array
    {
        $row = self::row($id) ?? throw new HttpException(404, 'Provider not found.');
        $res = self::client($row)->balance();
        $balance = null;
        $ok = false;
        if ($res->ok() && isset($res->data['balance'])) {
            $ok = true;
            $balance = trim((string) $res->data['balance'] . ' ' . (string) ($res->data['currency'] ?? $row['currency']));
            $message = 'Connected. Provider balance: ' . $balance . '.';
        } elseif ($res->ok()) {
            $message = 'The provider answered but did not return a balance. Check the API URL points to the provider’s v2 API endpoint.';
        } else {
            $message = match ($res->kind) {
                SmmResponse::REJECTED => 'The provider rejected the request: ' . $res->error,
                SmmResponse::UNREACHABLE => 'Could not reach the provider: ' . $res->error,
                default => 'The provider did not answer cleanly: ' . $res->error,
            };
        }
        Db::update('smm_providers', [
            'last_tested_at' => Clock::sql(), 'last_test_ok' => $ok ? 1 : 0, 'last_test_message' => mb_substr($message, 0, 255), 'last_balance' => $balance,
        ], 'id = ?', [$id]);
        Audit::log('smm.provider_tested', 'smm_provider', $id, ['ok' => $ok], $req, $admin);
        return ['ok' => $ok, 'message' => $message, 'balance' => $balance, 'duration_ms' => $res->durationMs, 'level' => $ok ? 'ok' : 'error'];
    }

    /**
     * Search the provider's service catalogue (cached 10 minutes) so an admin can
     * pick an SMM service ID instead of copying it by hand.
     *
     * @return array{ok:bool,message:string,items:list<array<string,mixed>>}
     */
    public static function searchServices(int $id, string $query): array
    {
        $row = self::row($id) ?? throw new HttpException(404, 'Provider not found.');
        $cache = SMB_ROOT . '/storage/cache/smm-services-' . $id . '.json';
        $list = null;
        if (is_file($cache) && filemtime($cache) > Clock::now() - 600) {
            $list = json_decode((string) file_get_contents($cache), true);
        }
        if (!is_array($list)) {
            $res = self::client($row)->services();
            if (!$res->ok() || !is_array($res->data) || !array_is_list($res->data)) {
                return ['ok' => false, 'message' => $res->error !== '' ? $res->error : 'The provider did not return a service list.', 'items' => []];
            }
            $list = [];
            foreach ($res->data as $s) {
                if (is_array($s) && isset($s['service'])) {
                    $list[] = [
                        'service' => (string) $s['service'], 'name' => mb_substr((string) ($s['name'] ?? ''), 0, 200),
                        'category' => mb_substr((string) ($s['category'] ?? ''), 0, 100), 'rate' => (string) ($s['rate'] ?? ''),
                        'min' => (string) ($s['min'] ?? ''), 'max' => (string) ($s['max'] ?? ''),
                    ];
                }
            }
            @file_put_contents($cache, json_encode($list), LOCK_EX);
        }
        $q = mb_strtolower(trim($query));
        $items = [];
        foreach ($list as $s) {
            if ($q === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['category'] . ' ' . $s['service']), $q)) {
                $items[] = $s;
                if (count($items) >= 40) {
                    break;
                }
            }
        }
        return ['ok' => true, 'message' => count($list) . ' services available', 'items' => $items];
    }
}
