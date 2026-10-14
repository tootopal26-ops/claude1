<?php
/** php tests/run.php [filter] */
declare(strict_types=1);

// Use the dedicated test database and local-only safety switches BEFORE the app boots.
putenv('DB_NAME=smb_test');
putenv('APP_ENV=local');
putenv('APP_DEBUG=false');
putenv('ALLOW_PRIVATE_API_HOSTS=true');
putenv('APP_URL=http://127.0.0.1:8080');

define('SMB_ROOT', dirname(__DIR__));
require SMB_ROOT . '/app/bootstrap.php';
require __DIR__ . '/TestCase.php';

use App\Core\Db;
use App\Support\Migrator;

Db::exec('SET FOREIGN_KEY_CHECKS = 0');
Migrator::run();
Db::exec('SET FOREIGN_KEY_CHECKS = 1');

$filter = $argv[1] ?? '';
foreach (array_merge(glob(__DIR__ . '/Unit/*.php') ?: [], glob(__DIR__ . '/Integration/*.php') ?: []) as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter) && !str_contains(strtolower(basename($file)), strtolower($filter))) {
        // still load: names inside may match
    }
    T::$currentFile = basename($file);
    require $file;
}

$pass = 0;
$fail = 0;
$failures = [];
$t0 = microtime(true);
foreach (T::$tests as [$name, $fn, $file]) {
    if ($filter !== '' && stripos($name . ' ' . $file, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (Throwable $e) {
        $fail++;
        $where = basename($e->getFile()) . ':' . $e->getLine();
        echo "  \033[31m✗ {$name}\033[0m\n      " . get_class($e) . ': ' . $e->getMessage() . "  ({$where})\n";
        $failures[] = $name;
    }
}
printf("\n%d passed, %d failed, %d assertions, %.1fs\n", $pass, $fail, T::$assertions, microtime(true) - $t0);
exit($fail > 0 ? 1 : 0);
