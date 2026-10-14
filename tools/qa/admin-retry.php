<?php
// QA helper (local dev only): what the admin's "Retry submit" button does, from the command line.   php tools/qa/admin-retry.php <order id>
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }
$admin = App\Core\Db::one('SELECT id, username, email FROM admins ORDER BY id LIMIT 1');
$r = App\Services\Fulfilment::submit((int) ($argv[1] ?? 0), ['id' => (int) $admin['id'], 'username' => $admin['username'], 'email' => $admin['email']], true);
echo $r['status'] . (isset($r['message']) ? ': ' . $r['message'] : '') . "\n";
