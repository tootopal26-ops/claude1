<?php /** Layout for the web installer. Expects: $content, $unlocked, $csrf */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Install — SOCIAL MEDIA BOOSTER</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<meta name="color-scheme" content="light">
<?php if ($csrf !== ''): ?><meta name="csrf-token" content="<?= e($csrf) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(asset('img/favicon-48.png')) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="ambient login-body" data-page="install" data-base="<?= e(base_path()) ?>">
<main id="main" tabindex="-1">
<?= $content ?>
</main>
<div class="toasts" id="toasts" role="region" aria-label="Notifications"></div>
<script type="module" src="<?= e(asset('js/install.js')) ?>"></script>
</body>
</html>
