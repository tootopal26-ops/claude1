<?php
// QA helper: put hostile strings into the newest order so escaping can be verified in the UI.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }
// A Followers / Subscribers order (link only) shows its normalised URL (`target_url`) instead of `target`, so BOTH carry the hostile text: whichever kind of
// order is the newest (the demo data decides), the string this check is about is on the screen — in link text and in the href attribute.
App\Core\Db::exec('UPDATE orders SET target = ?, target_url = ?, admin_note = ? ORDER BY id DESC LIMIT 1', [
    '<img src=x onerror=window.__xss=1>',
    'https://xss.example/?q=<img src=x onerror=window.__xss=1>"><svg onload=window.__xss=5>',
    '<script>window.__xss=2</script>"><svg onload=window.__xss=3>',
]);
echo "ok\n";
