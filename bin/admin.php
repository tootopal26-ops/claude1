<?php
/**
 * Admin account tools (locked out? forgot the password? need a second admin?):
 *
 *   php bin/admin.php list
 *   php bin/admin.php create --username=NAME --email=EMAIL [--password=…] [--must-change-password]
 *   php bin/admin.php reset-password --username=NAME [--password=…] [--must-change-password]
 *   php bin/admin.php unlock --username=NAME
 *   php bin/admin.php sessions-revoke --username=NAME
 *
 * Without --password a strong one is generated and printed once. Every command is recorded in the audit log.
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use App\Core\Db;
use App\Services\Audit;
use App\Services\Password;
use App\Support\Clock;

$cmd = $argv[1] ?? 'help';
// getopt() stops at the first non-option (the sub-command), so parse "--key=value", "--key value" and "--flag" by hand
$o = [];
$rest = array_slice($argv, 2);
for ($i = 0; $i < count($rest); $i++) {
    if (!str_starts_with($rest[$i], '--')) {
        continue;
    }
    $kv = explode('=', substr($rest[$i], 2), 2);
    if (count($kv) === 2) {
        $o[$kv[0]] = $kv[1];
    } elseif (in_array($kv[0], ['username', 'email', 'password'], true) && isset($rest[$i + 1]) && !str_starts_with($rest[$i + 1], '--')) {
        $o[$kv[0]] = $rest[++$i];
    } else {
        $o[$kv[0]] = true;
    }
}
$user = isset($o['username']) ? (string) $o['username'] : '';

$generate = static fn(): string => Password::generate();
$find = static function () use ($user): array {
    $row = $user !== '' ? Db::one('SELECT * FROM admins WHERE username = ? OR email = ?', [$user, $user]) : null;
    if ($row === null) {
        fwrite(STDERR, "No admin found with that username/email. Use: php bin/admin.php list\n");
        exit(1);
    }
    return $row;
};
$audit = static fn(string $action, array $row) => Audit::log($action, 'admin', $row['id'], ['via' => 'cli'], null, ['id' => (int) $row['id'], 'username' => 'cli', 'email' => '']);

switch ($cmd) {
    case 'list':
        foreach (Db::all('SELECT id, username, email, role, is_active, locked_until, last_login_at FROM admins ORDER BY id') as $a) {
            $locked = $a['locked_until'] !== null && Clock::fromSql($a['locked_until']) > Clock::now() ? ' LOCKED until ' . $a['locked_until'] . ' UTC' : '';
            printf("#%d  %-20s %-30s %s%s%s\n", $a['id'], $a['username'], $a['email'], $a['role'], $a['is_active'] ? '' : '  (disabled)', $locked);
        }
        break;

    case 'create':
        $email = (string) ($o['email'] ?? '');
        $pw = isset($o['password']) ? (string) $o['password'] : $generate();
        $problems = Password::problems($pw, ['username' => $user, 'email' => $email]);
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/D', $user) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $problems) {
            fwrite(STDERR, "Invalid input. Username: 3–64 letters/numbers/._-  Email must be valid." . ($problems ? ' Password: ' . implode(' ', $problems) : '') . "\n");
            exit(1);
        }
        if (Db::one('SELECT id FROM admins WHERE username = ? OR email = ?', [$user, strtolower($email)])) {
            fwrite(STDERR, "An admin with that username or email already exists.\n");
            exit(1);
        }
        $now = Clock::sql();
        $id = Db::insert('admins', ['username' => $user, 'email' => strtolower($email), 'password_hash' => Password::hash($pw), 'role' => 'owner', 'is_active' => 1,
            'must_change_password' => isset($o['must-change-password']) ? 1 : 0, 'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $audit('admin.created', ['id' => $id]);
        echo "Admin '{$user}' created." . (isset($o['password']) ? '' : "\nPassword (shown once): {$pw}") . "\n";
        break;

    case 'reset-password':
        $a = $find();
        $pw = isset($o['password']) ? (string) $o['password'] : $generate();
        $problems = Password::problems($pw, ['username' => (string) $a['username'], 'email' => (string) $a['email']]);
        if ($problems) {
            fwrite(STDERR, 'Password not accepted: ' . implode(' ', $problems) . "\n");
            exit(1);
        }
        Db::update('admins', ['password_hash' => Password::hash($pw), 'must_change_password' => isset($o['must-change-password']) ? 1 : 0, 'password_changed_at' => Clock::sql(),
            'failed_logins' => 0, 'locked_until' => null, 'is_active' => 1, 'updated_at' => Clock::sql()], 'id = ?', [$a['id']]);
        Db::exec('UPDATE sessions SET revoked_at = ? WHERE admin_id = ? AND revoked_at IS NULL', [Clock::sql(), $a['id']]);
        $audit('admin.password_reset', $a);
        echo "Password for '{$a['username']}' reset; all sessions signed out." . (isset($o['password']) ? '' : "\nNew password (shown once): {$pw}") . "\n";
        break;

    case 'unlock':
        $a = $find();
        Db::update('admins', ['failed_logins' => 0, 'locked_until' => null, 'updated_at' => Clock::sql()], 'id = ?', [$a['id']]);
        Db::exec('DELETE FROM rate_limits');
        $audit('admin.unlocked', $a);
        echo "'{$a['username']}' unlocked and sign-in rate limits cleared.\n";
        break;

    case 'sessions-revoke':
        $a = $find();
        $n = Db::exec('UPDATE sessions SET revoked_at = ? WHERE admin_id = ? AND revoked_at IS NULL', [Clock::sql(), $a['id']]);
        $audit('session.revoked', $a);
        echo "{$n} session(s) of '{$a['username']}' signed out.\n";
        break;

    default:
        echo trim((string) preg_replace('#^/\*\*|\s*\*/$#m', '', substr((string) file_get_contents(__FILE__), 6, 800))) . PHP_EOL;
}
