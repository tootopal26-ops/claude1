<?php
// QA helper (local only): write the daily report of every finished day of the dev database's history through the REAL report code (the same call the daily
// job makes), and leave a few watchdog alerts behind so the reports have something to say about them.   php tools/qa/reports-seed.php → JSON
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }

use App\Core\Db;
use App\Services\Alerts;
use App\Services\Analytics;
use App\Services\DailyReport;
use App\Support\Tz;

Alerts::raise('stuck_orders', 'critical', 'Paid orders are stuck', '2 orders have an unknown provider outcome.', ['dedupe_key' => 'wd:stuck_orders']);
Alerts::raise('sales_drop', 'warning', 'Verified sales have dropped', 'Down 60 % on the usual level.', ['dedupe_key' => 'wd:sales_drop']);

$first = Analytics::firstDay();
$yesterday = Tz::addDays(Tz::today(), -1);
$made = 0;
for ($d = $first; $d <= $yesterday; $d = Tz::addDays($d, 1)) {
    $made += DailyReport::generate($d, ['by' => 'cron'])['created'] ? 1 : 0;
}
echo json_encode(['first' => $first, 'yesterday' => $yesterday, 'created' => $made, 'total' => (int) Db::val('SELECT COUNT(*) FROM daily_reports')]), "\n";
