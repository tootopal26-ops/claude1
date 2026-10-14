<?php /** @var array $site */ ?>
<footer class="site-footer">
  <div class="shell text-center sm:flex sm:items-center sm:justify-between sm:text-left gap-4">
    <p><?= e($site['footer_text']) ?></p>
    <p class="mt-2 sm:mt-0 flex items-center justify-center gap-4 flex-wrap">
      <?php if ($site['support_link'] !== ''): ?><a href="<?= e($site['support_link']) ?>" rel="noopener noreferrer" target="_blank">Support</a><?php endif; ?>
      <span class="inline-flex items-center gap-1.5"><?= icon('lock', 'icon icon-sm') ?> Secure UPI payments</span>
    </p>
  </div>
</footer>
