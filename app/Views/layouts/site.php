<?php
/** Public layout. Expects: $content, $site, $title, $page, optional $description, $noindex, $canonical, $ogImage, $jsonLd, $scripts (list of entry names) */
$description = $description ?? $site['seo_description'];
$canonical = $canonical ?? absolute_url('/');
$ogImage = $ogImage ?? asset('img/og-default.png');
$ogImage = str_starts_with($ogImage, 'http') ? $ogImage : absolute_url(strtok($ogImage, '?'));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex, nofollow"><?php else: ?><meta name="robots" content="index, follow, max-image-preview:large"><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<meta name="theme-color" content="#ffffff">
<meta name="color-scheme" content="light">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($site['name']) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($description) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">
<link rel="icon" href="<?= e($site['favicon_url']) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(url('assets/fonts/inter-rupee.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css')) ?>?v=<?= e(substr(md5($site['accent_from'] . $site['accent_to']), 0, 8)) ?>">
<?php if (!empty($jsonLd)): ?><script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
</head>
<body class="ambient" data-page="<?= e($page ?? '') ?>" data-base="<?= e(base_path()) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?= \App\Core\View::partial('site-header', ['site' => $site, 'shop' => $shop ?? true]) ?>
<main id="main" tabindex="-1">
<?= $content ?>
</main>
<?= \App\Core\View::partial('site-footer', ['site' => $site]) ?>
<div class="toasts" id="toasts" role="region" aria-label="Notifications"></div>
<?php foreach (($scripts ?? []) as $entry): ?>
<script type="module" src="<?= e(asset('js/' . $entry . '.js')) ?>"></script>
<?php endforeach; ?>
</body>
</html>
