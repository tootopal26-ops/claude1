<?php /** Minimal layout for error pages. */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Error') ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= e($site['favicon_url'] ?? asset('img/favicon.svg')) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="ambient">
<main id="main">
<?= $content ?>
</main>
</body>
</html>
