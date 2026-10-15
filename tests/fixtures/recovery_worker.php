<?php
/** Test helper: run one recovery action after a "go" signal. php recovery_worker.php <orderId> <goFile> <action> [providerOrderId] */
declare(strict_types=1);
putenv('DB_NAME=smb_test'); putenv('APP_ENV=local'); putenv('ALLOW_PRIVATE_API_HOSTS=true'); putenv('APP_URL=http://127.0.0.1:8080');
define('SMB_ROOT', dirname(__DIR__, 2));
require SMB_ROOT . '/app/bootstrap.php';
[$script, $orderId, $goFile, $action] = $argv;
$deadline = microtime(true) + 10;
while (!is_file($goFile) && microtime(true) < $deadline) { usleep(500); }
$in = ['action' => $action];
if ($action === 'link_provider_order') {
    $in['provider_order_id'] = $argv[4] ?? '';
}
try {
    $r = App\Services\Recovery::act((int) $orderId, $in, null, ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner']);
    echo (string) ($r['result'] ?? 'done');
} catch (App\Core\HttpException $e) {
    echo 'error:' . ($e->errorCode !== '' ? $e->errorCode : (string) $e->status);
} catch (Throwable $e) {
    echo 'crash:' . get_class($e) . ':' . $e->getMessage();
}
