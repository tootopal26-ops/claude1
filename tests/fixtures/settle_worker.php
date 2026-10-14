<?php
/** Test helper: settle one payment, after a "go" signal so several workers collide. php settle_worker.php <paymentId> <goFile> */
declare(strict_types=1);
putenv('DB_NAME=smb_test'); putenv('APP_ENV=local'); putenv('ALLOW_PRIVATE_API_HOSTS=true'); putenv('APP_URL=http://127.0.0.1:8080');
define('SMB_ROOT', dirname(__DIR__, 2));
require SMB_ROOT . '/app/bootstrap.php';

[$script, $paymentId, $goFile] = $argv;
$deadline = microtime(true) + 10;
while (!is_file($goFile) && microtime(true) < $deadline) {
    usleep(500);
}
$r = App\Services\Settlement::settle((int) $paymentId, ['source' => 'webhook', 'amount_paise' => (int) ($argv[3] ?? 500), 'utr' => $argv[4] ?? null]);
echo json_encode($r['result']);
