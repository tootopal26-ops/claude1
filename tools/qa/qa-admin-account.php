<?php
// QA helper: make sure a second admin ("qa-admin") exists for password-change / lock-out tests.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }
use App\Core\Db;
use App\Services\Password;
use App\Support\Clock;
$pw = $argv[1] ?? 'Qa-Admin#Test-2026x';
$row = Db::one("SELECT id FROM admins WHERE username = 'qa-admin'");
$data = ['password_hash' => Password::hash($pw), 'is_active' => 1, 'must_change_password' => 0, 'failed_logins' => 0, 'locked_until' => null, 'updated_at' => Clock::sql()];
if ($row) { Db::update('admins', $data, 'id = ?', [$row['id']]); }
else { Db::insert('admins', $data + ['username' => 'qa-admin', 'email' => 'qa-admin@example.test', 'role' => 'owner', 'created_at' => Clock::sql()]); }
Db::exec("DELETE FROM sessions WHERE admin_id = (SELECT id FROM admins WHERE username = 'qa-admin')");
echo "qa-admin ready\n";
