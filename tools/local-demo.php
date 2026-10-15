<?php
/**
 * Run the WHOLE site on your own computer — storefront, admin panel, a fake payment gateway and a fake SMM panel, with demo data —
 * using ONE command and nothing else to configure (no Apache, no cPanel):
 *
 *     php tools/local-demo.php
 *
 * You need: PHP 8.1+ (extensions pdo_mysql, curl, mbstring, openssl, gd, fileinfo), a MySQL / MariaDB server that is running
 * (XAMPP, MAMP, Laragon, Homebrew, Docker …) and, for the fake payment gateway + SMM panel, Node.js 18+.
 * LOCAL USE ONLY: everything listens on 127.0.0.1 and uses throw-away demo credentials. For a real shop see docs/DEPLOY-CPANEL.md.
 *
 * Options:
 *   --db-host=127.0.0.1  --db-port=3306  --db-user=root  --db-pass=''     MySQL account allowed to create the demo database
 *   --db-name=smb_demo                                                      database to create / use
 *   --port=8080                                                             port of the local website
 *   --fresh                                                                 drop and rebuild the demo database + demo data
 *   --no-open                                                               do not open the browser
 *
 * Re-running keeps your demo data and admin password (stored in storage/local-demo-login.txt).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "This needs PHP 8.1 or newer (you have " . PHP_VERSION . ").\n");
    exit(1);
}
define('SMB_ROOT', dirname(__DIR__));
chdir(SMB_ROOT);

$o = getopt('', ['db-host:', 'db-port:', 'db-user:', 'db-pass:', 'db-name:', 'port:', 'fresh', 'no-open', 'help']);
if (isset($o['help'])) {
    echo trim((string) preg_replace('#^/\*\*|\s*\*/\s*$#m', '', substr((string) file_get_contents(__FILE__), 6, 1700))) . PHP_EOL;
    exit(0);
}
$opt = static fn(string $k, string $d): string => isset($o[$k]) && is_string($o[$k]) ? $o[$k] : $d;
$cfg = [
    'host' => $opt('db-host', '127.0.0.1'), 'port' => (int) $opt('db-port', '3306'), 'user' => $opt('db-user', 'root'), 'pass' => $opt('db-pass', ''),
    'name' => $opt('db-name', 'smb_demo'), 'web' => (int) $opt('port', '8080'), 'fresh' => isset($o['fresh']), 'open' => !isset($o['no-open']),
];
const GATEWAY_PORT = 4010;   // tools/dev-setup.php points the demo gateway / SMM provider at these two ports
const SMM_PORT = 4020;
$envFile = SMB_ROOT . '/.env';
$lockFile = SMB_ROOT . '/storage/installed.lock';
$loginFile = SMB_ROOT . '/storage/local-demo-login.txt';
$marker = '# local-demo: created by tools/local-demo.php (safe to delete together with the demo database)';

$say = static function (string $m = ''): void { echo $m . PHP_EOL; };
$step = static function (string $m) use ($say): void { $say(PHP_EOL . '>> ' . $m); };
$die = static function (string $m, int $code = 1) use ($say): never { $say(PHP_EOL . '[!!] ' . $m); exit($code); };

$say('SOCIAL MEDIA BOOSTER — local demo');

// ───────────────────────── 1. what this computer has ─────────────────────────
$step('Checking this computer');
$missing = array_values(array_filter(['pdo_mysql', 'curl', 'mbstring', 'openssl', 'gd', 'fileinfo'], static fn(string $e): bool => !extension_loaded($e)));
if ($missing) {
    $die('PHP is missing the extension(s): ' . implode(', ', $missing) . '. Enable them in php.ini (XAMPP: C:\\xampp\\php\\php.ini — remove the ";" in front of extension=' . $missing[0] . ') and run this again.');
}
$say('[ok] PHP ' . PHP_VERSION . ' with the needed extensions');

$node = null;
foreach (PHP_OS_FAMILY === 'Windows' ? ['node.exe', 'node'] : ['node'] as $n) {
    $out = [];
    $rc = 1;
    @exec(escapeshellarg($n) . ' --version 2>&1', $out, $rc);
    if ($rc === 0 && preg_match('/^v(\d+)\./', trim((string) ($out[0] ?? '')), $m) && (int) $m[1] >= 18) {
        $node = $n;
        $say('[ok] Node.js ' . trim($out[0]) . ' (runs the fake payment gateway and the fake SMM panel)');
        break;
    }
}
if ($node === null) {
    $say('[..] Node.js 18+ was not found: the site will run, but the fake payment gateway / SMM panel cannot start, so a checkout will say');
    $say('     "payments are temporarily unavailable". Install Node.js from https://nodejs.org and run this again for the full demo.');
}

if (portOpen($cfg['web'])) {
    $die("Port {$cfg['web']} is already in use (is the demo already running?). Pick another one with --port=8081.");
}

// ───────────────────────── 2. database + installation ─────────────────────────
$existing = is_file($envFile) ? (string) file_get_contents($envFile) : null;
if ($existing !== null && !str_contains($existing, $marker)) {
    $die("A .env file already exists here and it was not created by this launcher, so it may belong to a real installation. I will not touch it.\n     Run this from a fresh copy of the project, or move/rename the .env file first.");
}
$already = $existing !== null && is_file($lockFile) && !$cfg['fresh'];

require SMB_ROOT . '/app/bootstrap.php';

if ($already) {
    $step('Using the demo that is already installed (add --fresh to rebuild it from scratch)');
    $env = parseEnv($existing);
    $url = 'http://127.0.0.1:' . $cfg['web'];
    if (($env['APP_URL'] ?? '') !== $url) {
        file_put_contents($envFile, preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $url, $existing));
        $say('[ok] website address updated to ' . $url);
    }
    $login = is_file($loginFile) ? (string) file_get_contents($loginFile) : '';
} else {
    $step('Preparing the database "' . $cfg['name'] . '" on ' . $cfg['host'] . ':' . $cfg['port']);
    if (!preg_match('/^[A-Za-z0-9_]{1,60}$/', $cfg['name'])) {
        $die('The database name may only contain letters, digits and underscores.');
    }
    try {
        $pdo = new PDO('mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=utf8mb4', $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    } catch (PDOException $e) {
        $say('[!!] Could not connect to MySQL/MariaDB as "' . $cfg['user'] . '" on ' . $cfg['host'] . ':' . $cfg['port'] . ' — ' . preg_replace('/^SQLSTATE\[\w+\] \[\d+\] /', '', $e->getMessage()));
        $say('     • Is the database server running? (XAMPP: press Start next to MySQL. Homebrew: brew services start mariadb)');
        $say('     • Another port or password? e.g.  --db-port=8889 --db-pass=root   (MAMP)   or   --db-user=smb --db-pass=secret');
        $say('     • Linux: the "root" account usually needs sudo. Create a normal one once:');
        $say("         sudo mysql -e \"CREATE USER 'smb'@'127.0.0.1' IDENTIFIED BY 'smbpass'; GRANT ALL ON *.* TO 'smb'@'127.0.0.1'; CREATE USER 'smb'@'localhost' IDENTIFIED BY 'smbpass'; GRANT ALL ON *.* TO 'smb'@'localhost';\"");
        $say('       then run:  php tools/local-demo.php --db-user=smb --db-pass=smbpass');
        exit(1);
    }
    $quoted = '`' . $cfg['name'] . '`';
    if ($cfg['fresh']) {
        $pdo->exec('DROP DATABASE IF EXISTS ' . $quoted);
        @unlink($lockFile);
        @unlink($envFile);
        @unlink($loginFile);
        $say('[ok] old demo database removed');
    }
    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $say('[ok] database ready');

    $step('Installing the site');
    $password = \App\Services\Password::generate();
    $url = 'http://127.0.0.1:' . $cfg['web'];
    try {
        $installed = \App\Support\Installer::install([
            'url' => $url, 'db_host' => $cfg['host'], 'db_port' => (string) $cfg['port'], 'db_name' => $cfg['name'], 'db_user' => $cfg['user'], 'db_pass' => $cfg['pass'],
            'site_name' => 'SOCIAL MEDIA BOOSTER', 'timezone' => 'Asia/Kolkata', 'admin_username' => 'owner', 'admin_email' => 'owner@example.test',
            'admin_password' => $password, 'must_change' => false,
        ]);
    } catch (\App\Support\InstallException $e) {
        $die('Installation failed: ' . $e->getMessage() . ($e->fields ? ' (' . implode('; ', $e->fields) . ')' : ''));
    }
    // local-only switches: the fake gateway / SMM panel live on 127.0.0.1, which the SSRF guard refuses unless allowed
    $text = (string) file_get_contents($envFile);
    $text = preg_replace('/^ALLOW_PRIVATE_API_HOSTS=.*$/m', 'ALLOW_PRIVATE_API_HOSTS=true', $text);
    file_put_contents($envFile, $marker . "\n" . $text);
    if (!$installed['admin_created']) {
        $die('The database "' . $cfg['name'] . '" already contains an installation from earlier, so the new admin password could not be applied. Run again with --fresh to rebuild it.');
    }
    $login = "Website : {$url}\nAdmin   : {$url}/admin\nUsername: owner\nPassword: {$password}\n";
    file_put_contents($loginFile, $login);
    @chmod($loginFile, 0600);
    $say('[ok] tables created, catalogue and admin account added');

    $step('Adding demo data (about 75 days of orders, visitors and payments — takes a little while)');
    $rc = runCli([PHP_BINARY, 'tools/dev-setup.php', 'demo'], ['APP_ENV' => 'local', 'DEV_GATEWAY_STYLE' => 'headers']);   // dev tools insist on APP_ENV=local; the site itself runs as production
    if ($rc !== 0) {
        $die('The demo data could not be created (see the messages above).');
    }
    // the demo catalogue uses random SMM service ids; map the AUTO services onto the ones the fake SMM panel really offers
    \App\Core\Db::reset();
    foreach (['1001' => ['ig-followers-50'], '1002' => ['ig-likes-100', 'ig-likes-50k', 'ig-views-1k'], '2001' => ['yt-subs-50', 'yt-likes-100', 'yt-views-10', 'yt-views-10k']] as $id => $codes) {
        \App\Core\Db::exec("UPDATE services SET smm_service_id = ? WHERE code IN ('" . implode("','", $codes) . "')", [$id]);
    }
    $say('[ok] demo data ready');
}

// ───────────────────────── 3. the fake gateway + SMM panel, then the website ─────────────────────────
$procs = [];
$stop = static function () use (&$procs): void {
    foreach ($procs as $p) {
        if (is_resource($p['proc'])) {
            @proc_terminate($p['proc']);
        }
    }
};
register_shutdown_function($stop);
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function (): never { exit(0); });
    pcntl_signal(SIGTERM, static function (): never { exit(0); });
}
@mkdir(SMB_ROOT . '/storage/logs', 0775, true);

$step('Starting the local servers');
if ($node !== null) {
    foreach ([['gateway', GATEWAY_PORT, 'tools/mock-alpha-upi/server.js', []], ['smm', SMM_PORT, 'tools/mock-smm/server.js', ['--auto-progress']]] as [$name, $port, $script, $extra]) {
        if (portOpen($port)) {
            $say("[ok] fake {$name} already running on port {$port}");
            continue;
        }
        $procs[$name] = spawnProc([$node, $script, (string) $port, ...$extra], SMB_ROOT . "/storage/logs/demo-{$name}.log");
    }
}
$web = spawnProc([PHP_BINARY, '-S', '127.0.0.1:' . $cfg['web'], '-t', 'public', 'tools/router.php'], SMB_ROOT . '/storage/logs/demo-web.log', PHP_OS_FAMILY === 'Windows' ? [] : ['PHP_CLI_SERVER_WORKERS' => '4']);
$procs['website'] = $web;

$ready = static function (string $url, int $seconds = 30): bool {
    for ($i = 0; $i < $seconds * 4; $i++) {
        if (httpRequest('GET', $url)['status'] === 200) {
            return true;
        }
        usleep(250_000);
    }
    return false;
};
if (!$ready("http://127.0.0.1:{$cfg['web']}/health")) {
    $die('The website did not start. Details: storage/logs/demo-web.log');
}
$say("[ok] website on port {$cfg['web']}");
if ($node !== null) {
    if (isset($procs['gateway']) || portOpen(GATEWAY_PORT)) {
        $ready('http://127.0.0.1:' . GATEWAY_PORT . '/__state', 15);
        // tell the fake gateway where to send its "payment received" webhook
        $gid = (int) \App\Core\Db::val('SELECT id FROM payment_gateways ORDER BY id LIMIT 1');
        $r = httpRequest('POST', 'http://127.0.0.1:' . GATEWAY_PORT . '/__control', ['style' => 'headers', 'apiKey' => 'test-api-key-123', 'apiSecret' => 'test-api-secret-789', 'webhookSecret' => 'test-webhook-secret-456', 'webhookUrl' => "http://127.0.0.1:{$cfg['web']}/webhooks/payments/{$gid}"]);
        $say($r['status'] === 200 ? '[ok] fake payment gateway connected to the website' : '[!!] the fake payment gateway did not answer (see storage/logs/demo-gateway.log)');
    }
    $ready('http://127.0.0.1:' . SMM_PORT . '/__state', 15);
    $say(portOpen(SMM_PORT) ? '[ok] fake SMM panel running (orders move Pending → In progress → Completed by themselves)' : '[!!] the fake SMM panel did not start (see storage/logs/demo-smm.log)');
}

$base = "http://127.0.0.1:{$cfg['web']}";
$say(PHP_EOL . str_repeat('=', 72));
$say(' READY — leave this window open; press Ctrl+C to stop everything.');
$say(str_repeat('=', 72));
$say(" Website : {$base}");
$say(" Admin   : {$base}/admin");
foreach (preg_split('/\R/', trim($login)) as $line) {
    if (str_starts_with($line, 'Username') || str_starts_with($line, 'Password')) {
        $say(' ' . $line);
    }
}
if ($node !== null) {
    $say(PHP_EOL . ' Try it: press BUY NOW on any card, paste a profile / channel / post link, PROCEED TO PAYMENT, then on the');
    $say(' payment page press PAY NOW and use "Simulate payment success" on the fake gateway page.');
} else {
    $say(PHP_EOL . ' Browse the storefront freely. A checkout will say "payments are temporarily unavailable" until you install');
    $say(' Node.js (https://nodejs.org) and run this command again — then the fake payment gateway works too.');
}
$say(' The admin panel has about 75 days of demo history (Dashboard, Orders, Services, Payments, …).');
$say(' (Your login is also saved in storage/local-demo-login.txt)' . PHP_EOL);

if ($cfg['open']) {
    openBrowser($base);
}

// stay alive: keep the servers running, run the background tasks (cron) every minute, warn if something died
$lastCron = 0;
while (true) {
    sleep(1);
    foreach ($procs as $name => $p) {
        $st = proc_get_status($p['proc']);
        if (!$st['running']) {
            $say("[!!] {$name} stopped unexpectedly — see " . basename($p['log']));
            unset($procs[$name]);
            if ($name === 'website') {
                exit(1);
            }
        }
    }
    if (time() - $lastCron >= 60) {
        $lastCron = time();
        runCli([PHP_BINARY, 'bin/cron.php'], [], false);
    }
}

// ───────────────────────── helpers ─────────────────────────
function portOpen(int $port): bool
{
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
    if ($s === false) {
        return false;
    }
    fclose($s);
    return true;
}

/** @param array<string,mixed>|null $json @return array{status:int,body:string} */
function httpRequest(string $method, string $url, ?array $json = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 2]);
    if ($json !== null) {
        curl_setopt_array($ch, [CURLOPT_POSTFIELDS => json_encode($json), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/** @param list<string> $cmd @param array<string,string> $env @return array{proc:resource,log:string} */
function spawnProc(array $cmd, string $log, array $env = []): array
{
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, SMB_ROOT, $env ? array_merge(getenv(), $env) : null);
    if (!is_resource($proc)) {
        fwrite(STDERR, "[!!] could not start: " . implode(' ', $cmd) . PHP_EOL);
        exit(1);
    }
    return ['proc' => $proc, 'log' => $log];
}

/** Run a PHP CLI script to completion, showing its output. @param list<string> $cmd @param array<string,string> $env */
function runCli(array $cmd, array $env = [], bool $show = true): int
{
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    // read the child's output through a pipe and print it here (sharing our own stdout can interleave badly when it is redirected to a file)
    $proc = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, SMB_ROOT, array_merge(getenv(), $env));
    if (!is_resource($proc)) {
        return 1;
    }
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1]);
        if ($line !== false && $show) {
            echo $line;
        }
    }
    fclose($pipes[1]);
    return proc_close($proc);
}

/** @return array<string,string> */
function parseEnv(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m)) {
            $out[$m[1]] = trim($m[2], "\"'");
        }
    }
    return $out;
}

function openBrowser(string $url): void
{
    try {
        match (PHP_OS_FAMILY) {
            'Windows' => pclose(popen('start "" ' . escapeshellarg($url), 'r')),
            'Darwin' => exec('open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &'),
            default => exec('xdg-open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &'),
        };
    } catch (\Throwable) {
        // no browser to open: the address is printed above
    }
}
