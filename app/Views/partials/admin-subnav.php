<?php
/** Tabs shared by the two service-management pages. Expects: $active ('services' | 'tags'). */
$tabs = ['services' => ['Services', 'layers'], 'tags' => ['Tags & Badges', 'tags']];
?>
<nav class="seg mb-5" aria-label="Service management">
  <?php foreach ($tabs as $key => [$label, $ico]): ?>
  <a href="<?= e(url('/admin/' . $key)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= icon($ico, 'icon-sm') ?><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</nav>
