<?php
/** Admin navigation (rendered twice: desktop sidebar and mobile drawer). Expects: $page, $admin, $site, $idp */
$groups = [
    ['Overview', [['dashboard', 'Dashboard', 'layout-dashboard']]],
    ['Sales', [['orders', 'Orders', 'package'], ['recovery', 'Order recovery', 'life-buoy', true], ['payments', 'Payments', 'credit-card'], ['payment-checks', 'Payment checks', 'shield-alert', true], ['services', 'Services', 'layers'], ['tags', 'Tags & Badges', 'tags', true]]],
    ['Configuration', [['website', 'Website', 'globe'], ['gateway', 'Payment gateway', 'wallet'], ['smm', 'SMM API', 'server'], ['advertising', 'Advertising', 'megaphone']]],
    ['Account', [['security', 'Security', 'shield-check'], ['audit', 'Audit log', 'history'], ['system', 'System', 'activity']]],
];
// A role only sees the pages it may open (the server enforces the same rule on every page and API call).
$allowed = static function (string $key) use ($admin): bool {
    $perm = \App\Controllers\Admin\PageController::PAGES[$key][2] ?? null;
    return $perm === null || \App\Support\Permissions::can((string) $admin['role'], $perm);
};
$groups = array_values(array_filter(array_map(
    static fn(array $g): array => [$g[0], array_values(array_filter($g[1], static fn(array $i): bool => $allowed($i[0])))],
    $groups
), static fn(array $g): bool => $g[1] !== []));
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
        <?php if ($key === 'recovery'): ?><span class="adm-count" data-recovery-count hidden title="Paid orders that need a person (unknown outcome, review, failed)">0</span><?php endif; ?>
        <?php if ($key === 'payment-checks'): ?><span class="adm-count" data-flag-count hidden title="Payment flags that need a person to look">0</span><?php endif; ?>
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
