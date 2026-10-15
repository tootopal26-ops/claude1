<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Payment checks',
    'sub' => 'Every payment against what the gateway reported — amount, currency, reference, signature and timing. A flag asks a person to look; nothing here blocks a customer or moves money.',
]) ?>
<div id="pc-summary" class="mb-5" aria-live="polite" aria-busy="true">
  <div class="kpi-grid" aria-hidden="true"><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div></div>
</div>
<div class="seg mb-4" role="tablist" aria-label="Payment checks sections" id="pc-tabs">
  <button type="button" role="tab" id="pc-tab-payments" data-tab="payments" aria-selected="true" aria-controls="pc-root"><?= icon('credit-card', 'icon-sm') ?><span>Payments</span></button>
  <button type="button" role="tab" id="pc-tab-flags" data-tab="flags" aria-selected="false" aria-controls="pc-root" tabindex="-1"><?= icon('shield-alert', 'icon-sm') ?><span>Flags</span></button>
  <button type="button" role="tab" id="pc-tab-settings" data-tab="settings" aria-selected="false" aria-controls="pc-root" tabindex="-1"><?= icon('sliders-horizontal', 'icon-sm') ?><span>Thresholds</span></button>
</div>
<section class="panel toolbar" id="pc-toolbar" aria-label="Filters"></section>
<div id="pc-root" role="tabpanel" aria-live="polite" aria-busy="true" aria-labelledby="pc-tab-payments"><div class="skeleton h-72"></div></div>
