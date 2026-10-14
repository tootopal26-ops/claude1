<?php /** Layout for the sign-in page. Expects: $content, $site, $title, $page, $scripts */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<meta name="color-scheme" content="light">
<link rel="icon" href="<?= e($site['favicon_url']) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css')) ?>?v=<?= e(substr(md5($site['accent_from'] . $site['accent_to']), 0, 8)) ?>">
</head>
<body class="ambient login-body" data-page="<?= e($page) ?>" data-base="<?= e(base_path()) ?>">
<main id="main" tabindex="-1">
<?= $content ?>
</main>
<?php foreach (($scripts ?? []) as $entry): ?>
<script type="module" src="<?= e(asset('js/' . $entry . '.js')) ?>"></script>
<?php endforeach; ?>
</body>
</html>
