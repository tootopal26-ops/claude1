<?php /** @var array $site */ ?>
<header class="site-header">
  <div class="glass">
    <div class="shell site-header-bar">
      <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e($site['name']) ?> — home">
        <span class="brand-logo rgb-ring"><img src="<?= e($site['logo_url']) ?>" width="46" height="46" alt="" decoding="async"></span>
        <span class="brand-name"><?= e($site['name']) ?></span>
      </a>
      <?php if ($shop ?? true): ?>
        <?php if (($page ?? '') === 'home'): ?>
      <button type="button" class="shop-btn" data-action="open-orders" aria-label="Open your orders" aria-haspopup="dialog">
        <?= icon('shop') ?>
        <span class="shop-dot" data-orders-count hidden>0</span>
      </button>
        <?php else: /* the orders drawer lives on the home page: other pages (order tracking) link to it */ ?>
      <a class="shop-btn" href="<?= e(url('/')) ?>?orders=1" aria-label="Open your orders"><?= icon('shop') ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</header>
