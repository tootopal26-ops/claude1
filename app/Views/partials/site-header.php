<?php /** @var array $site */ ?>
<header class="site-header">
  <div class="glass">
    <div class="shell site-header-bar">
      <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e($site['name']) ?> — home">
        <span class="brand-logo rgb-ring"><img src="<?= e($site['logo_url']) ?>" width="46" height="46" alt="" decoding="async"></span>
        <span class="brand-name"><?= e($site['name']) ?></span>
      </a>
      <?php if ($shop ?? true): ?>
      <button type="button" class="shop-btn" data-action="open-orders" aria-label="Open your orders" aria-haspopup="dialog">
        <?= icon('shop') ?>
        <span class="shop-dot" data-orders-count hidden>0</span>
      </button>
      <?php endif; ?>
    </div>
  </div>
</header>
