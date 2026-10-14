<?php
/** Test helper: submit one order to the SMM provider after a "go" signal. php submit_worker.php <orderId> <goFile> */
declare(strict_types=1);
putenv('DB_NAME=smb_test'); putenv('APP_ENV=local'); putenv('ALLOW_PRIVATE_API_HOSTS=true'); putenv('APP_URL=http://127.0.0.1:8080');
define('SMB_ROOT', dirname(__DIR__, 2));
require SMB_ROOT . '/app/bootstrap.php';
[$script, $orderId, $goFile] = $argv;
$deadline = microtime(true) + 10;
while (!is_file($goFile) && microtime(true) < $deadline) { usleep(500); }
$what = $argv[3] ?? 'submit';
if ($what === 'approve_all') {
    $r = App\Services\Fulfilment::approveAll(['id' => 1, 'username' => 'root', 'email' => 'r@x.test'], App\Core\Request::fake('POST', '/x'));
    echo json_encode($r['approved']);
} else {
    echo json_encode(App\Services\Fulfilment::submit((int) $orderId)['status']);
}
