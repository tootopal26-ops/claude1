<?php
/**
 * Router script for PHP's built-in web server (development only):
 *   PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8080 -t public tools/router.php
 * Mimics public/.htaccess: real files are served directly, everything else → index.php.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$root = realpath(__DIR__ . '/../public');
$file = str_contains($path, "\0") ? false : realpath($root . $path);   // resolves ".." — anything outside public/ is never served
$inside = $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR);
if ($path !== '/' && $inside && is_file($file) && !preg_match('/\.(php|phtml|phar)$/i', $path)) {
    // PHP's built-in server guesses some types wrong; fix the ones we use.
    $types = ['woff2' => 'font/woff2', 'svg' => 'image/svg+xml', 'js' => 'text/javascript', 'webp' => 'image/webp', 'css' => 'text/css'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        header('X-Content-Type-Options: nosniff');
        header_remove('X-Powered-By');
        readfile($file);
        return true;
    }
    return false;
}
require __DIR__ . '/../public/index.php';
