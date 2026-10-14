<?php
/** @var array $site @var list<array> $platforms */
$first = $platforms[0]['slug'] ?? '';
?>
<div class="shell pt-4 sm:pt-6">
  <h1 class="sr-only"><?= e($site['name']) ?> — Instagram and YouTube growth services</h1>

  <?php if (!$platforms): ?>
    <div class="site-empty glass rounded-xl"><p class="font-semibold text-ink">Services are being updated.</p><p class="mt-1">Please check back in a few minutes.</p></div>
  <?php else: ?>
  <section aria-label="Choose a platform">
    <div class="platform-grid" role="tablist" aria-label="Platform" data-tablist>
      <?php foreach ($platforms as $i => $p): ?>
        <button type="button" role="tab" id="tab-<?= e($p['slug']) ?>" class="platform-btn glass rgb-ring rgb-ring-hover" data-platform-tab="<?= e($p['slug']) ?>"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="panel-<?= e($p['slug']) ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>">
          <span class="platform-logo"><img src="<?= e($p['logo_url']) ?>" width="68" height="68" alt="" decoding="async"<?= $i === 0 ? ' fetchpriority="high"' : '' ?>></span>
          <span class="min-w-0">
            <span class="platform-name block"><?= e($p['name']) ?></span>
            <span class="platform-tag"><?= e($p['tagline']) ?></span>
          </span>
          <span class="platform-check" aria-hidden="true"><?= icon('check') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <ul class="trust-row" aria-label="Why customers trust us">
      <li><?= icon('shield-check') ?> Secure UPI checkout</li>
      <li><?= icon('zap') ?> Fast order start</li>
      <li><?= icon('history') ?> Live order tracking</li>
    </ul>
  </section>

  <section id="services" class="pt-6 sm:pt-9 scroll-mt-24" aria-label="Services">
    <?php foreach ($platforms as $i => $p): ?>
      <div role="tabpanel" id="panel-<?= e($p['slug']) ?>" aria-labelledby="tab-<?= e($p['slug']) ?>" data-panel="<?= e($p['slug']) ?>" tabindex="0" class="outline-offset-8"<?= $i === 0 ? '' : ' hidden' ?>>
        <?php if (!$p['sections']): ?>
          <div class="site-empty glass rounded-xl">No services are available for <?= e($p['name']) ?> right now.</div>
        <?php endif; ?>
        <?php foreach ($p['sections'] as $sec): ?>
          <div class="sec-head"><?= icon(match ($sec['type']) { 'followers' => 'user-plus', 'likes' => 'heart', 'views' => 'eye', 'verification' => 'badge-check', 'subscribers' => 'bell-ring', 'watch_time' => 'clock', default => 'sparkles' }) ?><h2><?= e($sec['label']) ?></h2></div>
          <div class="svc-grid">
            <?php foreach ($sec['services'] as $s): ?>
              <?= \App\Core\View::partial('service-card', ['s' => $s, 'p' => $p]) ?>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</div>

<!-- "Your orders" drawer (opened by the shop icon) -->
<dialog class="dlg dlg-drawer" id="orders-drawer" aria-labelledby="orders-title">
  <div class="dlg-panel">
    <div class="dlg-head">
      <h2 id="orders-title" class="text-lg font-extrabold">Your orders</h2>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <div class="dlg-body" data-orders-body>
      <p class="text-sm text-muted">Loading your orders…</p>
    </div>
    <div class="dlg-foot justify-between max-sm:flex-col max-sm:items-stretch">
      <span class="text-xs text-muted self-center max-sm:order-2">Orders are remembered on this device.</span>
      <button type="button" class="btn btn-primary max-sm:order-1" data-dialog-close data-scroll="#services">Browse services</button>
    </div>
  </div>
</dialog>

<?= json_script('smb-config', ['base' => base_path(), 'maxTarget' => 300, 'platforms' => array_column($platforms, 'slug')]) ?>
