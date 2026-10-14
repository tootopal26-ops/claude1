<?php /** @var int $status @var string $heading @var string $message @var string $detail @var array $site */ ?>
<div class="pay-wrap">
  <div class="pay-card glass-strong">
    <div class="state-icon <?= $status >= 500 ? 'state-bad' : 'state-warn' ?>"><?= icon($status === 404 ? 'search' : ($status === 429 ? 'clock' : 'triangle-alert')) ?></div>
    <p class="mt-4 text-sm font-bold tracking-widest text-muted"><?= (int) $status ?></p>
    <h1 class="pay-title"><?= e($heading) ?></h1>
    <p class="pay-note"><?= e($message) ?></p>
    <?php if (!empty($detail)): ?><pre class="mt-3 text-left text-xs whitespace-pre-wrap break-words rounded-md bg-bad-50 p-3 text-bad-700"><?= e($detail) ?></pre><?php endif; ?>
    <div class="pay-actions"><a class="btn btn-primary btn-lg" href="<?= e(url('/')) ?>">Back to home</a></div>
  </div>
</div>
