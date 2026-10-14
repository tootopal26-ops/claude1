<?php
// QA helper: clear rate-limit buckets and un-lock admin accounts so scripted sign-in tests are repeatable.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }
App\Core\Db::exec('DELETE FROM rate_limits');
App\Core\Db::exec('UPDATE admins SET failed_logins = 0, locked_until = NULL');
App\Core\Db::exec('DELETE FROM idempotency_keys');
echo "limits reset\n";
