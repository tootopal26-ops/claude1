<?php
/** Test helper: SalesTarget::set() after a "go" signal, from its own DB connection. php sales_target_worker.php <goFile> <periodStart> <periodEnd> <target> */
declare(strict_types=1);
putenv('DB_NAME=smb_test'); putenv('APP_ENV=local'); putenv('ALLOW_PRIVATE_API_HOSTS=true'); putenv('APP_URL=http://127.0.0.1:8080');
define('SMB_ROOT', dirname(__DIR__, 2));
require SMB_ROOT . '/app/bootstrap.php';
[$script, $goFile, $start, $end, $target] = $argv;
$deadline = microtime(true) + 10;
while (!is_file($goFile) && microtime(true) < $deadline) { usleep(500); }
try {
    $r = App\Services\SalesTarget::set(
        ['period_start' => $start, 'period_end' => $end, 'target' => $target],
        App\Core\Request::fake('PUT', '/x'),
        ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner']
    );
    echo (string) $r['target']['id'];
} catch (App\Core\HttpException $e) {
    echo 'error:' . ($e->errorCode !== '' ? $e->errorCode : (string) $e->status);
} catch (Throwable $e) {
    echo 'crash:' . get_class($e) . ':' . $e->getMessage();
}
