<?php
/** Test helper: AdBudget::save() (default scope) after a "go" signal, from its own DB connection. php ad_budget_worker.php <goFile> <dailyBudget> */
declare(strict_types=1);
putenv('DB_NAME=smb_test'); putenv('APP_ENV=local'); putenv('ALLOW_PRIVATE_API_HOSTS=true'); putenv('APP_URL=http://127.0.0.1:8080');
define('SMB_ROOT', dirname(__DIR__, 2));
require SMB_ROOT . '/app/bootstrap.php';
[$script, $goFile, $daily] = $argv;
$deadline = microtime(true) + 10;
while (!is_file($goFile) && microtime(true) < $deadline) { usleep(500); }
try {
    $r = App\Services\AdBudget::save(
        ['scope' => 'default', 'daily_budget' => $daily],
        App\Core\Request::fake('PUT', '/x'),
        ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner']
    );
    echo (string) $r['daily_budget_paise'];
} catch (App\Core\HttpException $e) {
    echo 'error:' . ($e->errorCode !== '' ? $e->errorCode : (string) $e->status);
} catch (Throwable $e) {
    echo 'crash:' . get_class($e) . ':' . $e->getMessage();
}
