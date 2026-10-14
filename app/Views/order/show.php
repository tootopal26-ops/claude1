<?php /** @var array $site @var array $o customer view @var string $token */ ?>
<div class="pay-wrap">
  <div class="pay-card glass-strong" id="order-root" data-token="<?= e($token) ?>">
    <p class="pay-kicker"><?= icon('history', 'icon icon-sm') ?> Order status</p>
    <h1 class="pay-title" id="o-heading"><?= e($o['status_label']) ?></h1>
    <p class="pay-summary"><b class="num" id="o-amount"><?= e($o['amount']) ?></b><span><?= e($o['service']) ?></span></p>
    <p class="pay-note">Order <b class="text-ink"><?= e($o['order_id']) ?></b> · for <b class="text-ink break-all"><?= e($o['target']) ?></b></p>
    <div class="stepper" id="o-steps" aria-live="polite">
      <?php foreach ($o['steps'] as $i => $st): ?>
        <div class="step" data-state="<?= $i === count($o['steps']) - 1 && !$o['done'] ? 'now' : 'done' ?>">
          <span class="step-dot"><?= icon('check') ?></span>
          <div><b><?= e($st['label']) ?></b><small><time datetime="<?= e($st['at']) ?>"><?= e(gmdate('d M Y, H:i', (int) strtotime($st['at'])) . ' UTC') ?></time></small></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="pay-actions">
      <a class="btn btn-primary btn-lg" href="<?= e(url('/')) ?>#services"><?= icon('rocket') ?> BUY MORE</a>
      <?php if ($site['support_link'] !== ''): ?><a class="btn btn-ghost" href="<?= e($site['support_link']) ?>" target="_blank" rel="noopener noreferrer">Need help? Contact support</a><?php endif; ?>
    </div>
  </div>
</div>
<?= json_script('order-data', ['token' => $token, 'statusUrl' => url('api/order/' . $token), 'status' => $o['status'], 'done' => $o['done']]) ?>
