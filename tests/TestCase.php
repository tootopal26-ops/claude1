<?php
/**
 * Minimal test harness (no Composer / PHPUnit needed — runs on any PHP 8.1+).
 *
 *   php tests/run.php            # everything
 *   php tests/run.php money      # only test names / files containing "money"
 */
declare(strict_types=1);

use App\Core\Db;

final class T
{
    /** @var list<array{0:string,1:callable,2:string}> */
    public static array $tests = [];
    public static string $currentFile = '';
    public static int $assertions = 0;
    /** @var list<resource> */
    private static array $procs = [];

    public static function test(string $name, callable $fn): void
    {
        self::$tests[] = [$name, $fn, self::$currentFile];
    }

    public static function same(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            throw new AssertionError(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
        }
    }

    public static function true(mixed $cond, string $msg = 'expected true'): void
    {
        self::$assertions++;
        if ($cond !== true) {
            throw new AssertionError($msg . ' (got ' . var_export($cond, true) . ')');
        }
    }

    public static function false(mixed $cond, string $msg = 'expected false'): void
    {
        self::$assertions++;
        if ($cond !== false) {
            throw new AssertionError($msg . ' (got ' . var_export($cond, true) . ')');
        }
    }

    public static function contains(string $needle, string $haystack, string $msg = ''): void
    {
        self::$assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionError(($msg !== '' ? $msg . ': ' : '') . "expected '{$haystack}' to contain '{$needle}'");
        }
    }

    /** @param class-string<Throwable> $class */
    public static function throws(string $class, callable $fn, ?int $httpStatus = null): Throwable
    {
        self::$assertions++;
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionError("expected {$class} but got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($httpStatus !== null && $e instanceof \App\Core\HttpException && $e->status !== $httpStatus) {
                throw new AssertionError("expected HTTP {$httpStatus} but got {$e->status}: " . $e->getMessage());
            }
            return $e;
        }
        throw new AssertionError("expected {$class} to be thrown, nothing was");
    }

    // ── database ──────────────────────────────────────────────────────────────

    /** Empty every table, then re-seed catalogue + CPC + a gateway/SMM provider pointing at the mock servers. */
    public static function freshDb(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Db::col('SHOW TABLES') as $t) {
            if ($t !== 'schema_migrations') {
                $pdo->exec('TRUNCATE TABLE `' . $t . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        \App\Services\Settings::flush();
        \App\Support\Clock::setFixed(null);
        \App\Support\Seeder::all();
    }

    /** Configure the (inactive-by-default) gateway to talk to the mock Alpha UPI server and activate it. */
    public static function configureGateway(string $key = 'test-api-key-123', string $secret = 'test-webhook-secret-456', int $port = 4010): int
    {
        $row = Db::one('SELECT id FROM payment_gateways ORDER BY id LIMIT 1');
        $id = (int) $row['id'];
        Db::update('payment_gateways', [
            'is_active' => 1, 'environment' => 'test',
            'config_json' => json_encode(['base_url' => "http://127.0.0.1:{$port}", 'provider_pref' => 'auto']),
            'secrets_enc' => \App\Core\Crypto::encryptJson(['api_key' => $key, 'webhook_secret' => $secret]),
        ], 'id = ?', [$id]);
        return $id;
    }

    public static function configureSmm(string $key = 'smm-key-ok', int $port = 4020): int
    {
        Db::exec('DELETE FROM smm_providers');
        return Db::insert('smm_providers', [
            'name' => 'Mock SMM', 'api_url' => "http://127.0.0.1:{$port}/api/v2", 'api_key_enc' => \App\Core\Crypto::encrypt($key),
            'currency' => 'USD', 'rate_to_inr_micros' => 83_500_000, 'is_active' => 1, 'is_default' => 1,
            'created_at' => \App\Support\Clock::sql(), 'updated_at' => \App\Support\Clock::sql(),
        ]);
    }

    /** Switch a seeded service to AUTO with an SMM id and cost. */
    public static function makeAuto(string $code, string $smmServiceId = '1001', int $costPaise = 100, ?int $providerId = null): int
    {
        $svc = Db::one('SELECT id FROM services WHERE code = ?', [$code]);
        Db::update('services', ['mode' => 'AUTO', 'smm_service_id' => $smmServiceId, 'smm_cost_paise' => $costPaise, 'smm_provider_id' => $providerId], 'id = ?', [$svc['id']]);
        return (int) $svc['id'];
    }

    // ── mock servers ──────────────────────────────────────────────────────────

    public static function startMocks(): void
    {
        foreach ([[4010, 'tools/mock-alpha-upi/server.js'], [4020, 'tools/mock-smm/server.js']] as [$port, $file]) {
            if (self::portOpen($port)) {
                continue;
            }
            $proc = proc_open(['node', SMB_ROOT . '/' . $file, (string) $port], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
            self::$procs[] = $proc;
            for ($i = 0; $i < 50 && !self::portOpen($port); $i++) {
                usleep(100_000);
            }
            if (!self::portOpen($port)) {
                throw new RuntimeException("mock server on {$port} did not start");
            }
        }
        register_shutdown_function([self::class, 'stopMocks']);
    }

    public static function stopMocks(): void
    {
        foreach (self::$procs as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
            }
        }
        self::$procs = [];
    }

    private static function portOpen(int $port): bool
    {
        $s = @fsockopen('127.0.0.1', $port, $e, $m, 0.3);
        if ($s) {
            fclose($s);
            return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    public static function mock(int $port, string $method, string $path, array $body = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$port}{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $out = curl_exec($ch);
        curl_close($ch);
        return is_string($out) ? (json_decode($out, true) ?? ['raw' => $out]) : [];
    }

    public static function alpha(string $method, string $path, array $body = []): array
    {
        return self::mock(4010, $method, $path, $body);
    }

    public static function smm(string $method, string $path, array $body = []): array
    {
        return self::mock(4020, $method, $path, $body);
    }
}
