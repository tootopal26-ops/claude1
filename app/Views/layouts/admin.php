<?php
/** Admin layout. Expects: $content, $site, $admin, $csrf, $page, $title, $pageTitle, $tz, $scripts */
$nav = \App\Core\View::partial('admin-nav', ['page' => $page, 'admin' => $admin, 'site' => $site, 'idp' => 'd']);
$navMobile = \App\Core\View::partial('admin-nav', ['page' => $page, 'admin' => $admin, 'site' => $site, 'idp' => 'm']);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<meta name="theme-color" content="#ffffff">
<meta name="color-scheme" content="light">
<link rel="icon" href="<?= e($site['favicon_url']) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css')) ?>?v=<?= e(substr(md5($site['accent_from'] . $site['accent_to']), 0, 8)) ?>">
</head>
<body class="ambient adm-body" data-page="<?= e($page) ?>" data-base="<?= e(base_path()) ?>" data-tz="<?= e($tz) ?>" data-admin="<?= e($admin['username']) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<div class="adm">
  <aside class="adm-side glass-strong" aria-label="Admin navigation">
    <?= $nav ?>
  </aside>

  <div class="adm-col">
    <header class="adm-mobilebar glass-strong">
      <button type="button" class="btn btn-ghost btn-icon" data-action="open-nav" aria-label="Open menu" aria-haspopup="dialog"><?= icon('menu', 'icon-lg') ?></button>
      <a class="adm-mobilebrand" href="<?= e(url('/admin/dashboard')) ?>">
        <img src="<?= e($site['logo_url']) ?>" width="32" height="32" alt="" decoding="async">
        <span><?= e($pageTitle) ?></span>
      </a>
      <span class="adm-mobilebar-end">
        <span class="chip chip-warn" data-approve-chip hidden></span>
      </span>
    </header>

    <main id="main" class="adm-main" tabindex="-1">
      <div id="banners" class="adm-banners" role="region" aria-label="System notices" aria-live="polite"></div>
      <noscript><div class="callout callout-warn">The admin panel needs JavaScript. Please enable it in your browser.</div></noscript>
      <?= $content ?>
    </main>
  </div>
</div>

<dialog class="dlg dlg-nav" id="nav-dialog" aria-label="Menu">
  <div class="dlg-panel glass-strong">
    <div class="flex items-center justify-end px-3 pt-3">
      <button type="button" class="btn btn-ghost btn-icon" data-dialog-close aria-label="Close menu"><?= icon('x', 'icon-lg') ?></button>
    </div>
    <?= $navMobile ?>
  </div>
</dialog>

<div class="toasts" id="toasts" role="region" aria-label="Notifications"></div>
<?php foreach (($scripts ?? []) as $entry): ?>
<script type="module" src="<?= e(asset('js/' . $entry . '.js')) ?>"></script>
<?php endforeach; ?>
</body>
</html>
