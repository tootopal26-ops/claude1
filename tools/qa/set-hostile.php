<?php
// QA helper: put hostile strings into the newest order so escaping can be verified in the UI.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }
App\Core\Db::exec('UPDATE orders SET target = ?, admin_note = ? ORDER BY id DESC LIMIT 1', [
    '<img src=x onerror=window.__xss=1>',
    '<script>window.__xss=2</script>"><svg onload=window.__xss=3>',
]);
echo "ok\n";
