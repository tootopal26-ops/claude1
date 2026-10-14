<?php
/**
 * Application bootstrap — shared by the web front controller (public/index.php),
 * the CLI tools in /bin and the test-suite.
 *
 * No Composer is required: a tiny PSR-4 autoloader maps  App\  →  /app  and the
 * bundled Alpha UPI SDK namespace  AlphaUpi\  →  /app/Vendor/AlphaUpi.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    echo 'This application needs PHP 8.1 or newer (found ' . PHP_VERSION . '). Choose a newer version in cPanel → MultiPHP Manager.';
    exit(1);
}

defined('SMB_ROOT') || define('SMB_ROOT', dirname(__DIR__));
defined('SMB_PUBLIC') || define('SMB_PUBLIC', SMB_ROOT . '/public');
defined('SMB_STARTED') || define('SMB_STARTED', microtime(true));

spl_autoload_register(static function (string $class): void {
    static $map = null;
    $map ??= [
        'App\\'      => SMB_ROOT . '/app/',
        'AlphaUpi\\' => SMB_ROOT . '/app/Vendor/AlphaUpi/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require SMB_ROOT . '/app/Support/helpers.php';

\App\Core\Env::load(SMB_ROOT . '/.env');

mb_internal_encoding('UTF-8');
// All internal timestamps are UTC. The *business* time zone is applied by App\Support\Tz.
date_default_timezone_set('UTC');

\App\Core\ErrorHandler::register();
