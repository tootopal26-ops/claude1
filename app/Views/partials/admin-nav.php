<?php
/** Admin navigation (rendered twice: desktop sidebar and mobile drawer). Expects: $page, $admin, $site, $idp */
$groups = [
    ['Overview', [['dashboard', 'Dashboard', 'layout-dashboard']]],
    ['Sales', [['orders', 'Orders', 'package'], ['payments', 'Payments', 'credit-card'], ['services', 'Services', 'layers'], ['tags', 'Tags & Badges', 'tags', true]]],
    ['Configuration', [['website', 'Website', 'globe'], ['gateway', 'Payment gateway', 'wallet'], ['smm', 'SMM API', 'server'], ['advertising', 'Advertising', 'megaphone']]],
    ['Account', [['security', 'Security', 'shield-check'], ['audit', 'Audit log', 'history'], ['system', 'System', 'activity']]],
];
?>
<div class="adm-nav">
  <a class="adm-brand" href="<?= e(url('/admin/dashboard')) ?>" aria-label="<?= e($site['name']) ?> admin home">
    <span class="adm-brand-logo rgb-ring"><img src="<?= e($site['logo_url']) ?>" width="40" height="40" alt="" decoding="async"></span>
    <span class="adm-brand-text"><strong><?= e($site['name']) ?></strong><small>Admin panel</small></span>
  </a>

  <nav class="adm-links" aria-label="Main">
    <?php foreach ($groups as [$label, $items]): ?>
    <div class="adm-group">
      <p class="adm-group-label"><?= e($label) ?></p>
      <?php foreach ($items as $item): [$key, $text, $ico] = $item; $sub = $item[3] ?? false; ?>
      <a class="adm-link<?= $page === $key ? ' is-active' : '' ?><?= $sub ? ' adm-sub' : '' ?>" href="<?= e(url('/admin/' . $key)) ?>"<?= $page === $key ? ' aria-current="page"' : '' ?>>
        <?= icon($ico) ?><span><?= e($text) ?></span>
        <?php if ($key === 'orders'): ?><span class="adm-count" data-approve-count hidden title="Manual orders waiting for approval">0</span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </nav>

  <div class="adm-foot">
    <a class="adm-link" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('external-link') ?><span>View store</span></a>
    <div class="adm-user">
      <span class="adm-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($admin['username'], 0, 1))) ?></span>
      <span class="adm-user-text"><strong><?= e($admin['username']) ?></strong><small><?= e(ucfirst($admin['role'])) ?></small></span>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-action="logout" aria-label="Sign out" title="Sign out"><?= icon('log-out') ?></button>
    </div>
  </div>
</div>
