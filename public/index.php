<?php
/**
 * Front controller — every request that is not a real file lands here.
 *
 * Folder layouts supported on cPanel:
 *   A) Everything in public_html/ (simple): keep the tree as shipped. The root
 *      .htaccess routes requests into /public and blocks app/, storage/, etc.
 *   B) Recommended: keep the PRIVATE code outside the web root, e.g.
 *        /home/USER/smb/          ← app/, bin/, config/, database/, storage/, .env
 *        /home/USER/public_html/  ← contents of /public (this file, assets/, uploads/, .htaccess)
 *      then set the path below to the private folder.
 */

declare(strict_types=1);

// Layout B: uncomment and point at the private folder.
// define('SMB_ROOT', '/home/USER/smb');

if (!defined('SMB_ROOT')) {
    define('SMB_ROOT', dirname(__DIR__));
}
define('SMB_PUBLIC', __DIR__);

require SMB_ROOT . '/app/bootstrap.php';

\App\Core\Kernel::run();
