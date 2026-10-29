<?php
/** Minimal layout for error pages. */
$theme = $site['theme'] ?? \App\Support\Themes::DEFAULT;
$density = $site['theme_density'] ?? \App\Support\Themes::DEFAULT_DENSITY;
$scheme = $site['color_scheme'] ?? \App\Support\Themes::colorScheme($theme);
$accentFrom = $site['accent_from'] ?? '#ff2d87';
$accentTo = $site['accent_to'] ?? '#ff3b3b';
?><!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Error') ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e($scheme === 'dark' ? '#15131c' : '#ffffff') ?>">
<meta name="color-scheme" content="<?= e($scheme) ?>">
<link rel="icon" href="<?= e($site['favicon_url'] ?? asset('img/favicon-48.png')) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css')) ?>?v=<?= e(substr(md5($theme . $density . $accentFrom . $accentTo), 0, 8)) ?>">
</head>
<body class="ambient">
<main id="main">
<?= $content ?>
</main>
</body>
</html>
