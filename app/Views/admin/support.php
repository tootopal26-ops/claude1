<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Support',
    'sub' => 'The customer chat assistant. Order-specific answers (payment status, order status) come only from the order’s own record — <strong>never from AI</strong>, whatever the settings below. Times are shown in <span data-tz-label>your business time zone</span>.',
]) ?>
<div id="sp-summary" class="mb-5" aria-live="polite" aria-busy="true">
  <div class="kpi-grid" aria-hidden="true"><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div></div>
</div>
<div class="seg seg-compact mb-4" role="tablist" aria-label="Support sections" id="sp-tabs">
  <button type="button" role="tab" id="sp-tab-conversations" data-tab="conversations" aria-selected="true" aria-controls="sp-root"><?= icon('message-circle', 'icon-sm') ?><span>Conversations</span></button>
  <button type="button" role="tab" id="sp-tab-settings" data-tab="settings" aria-selected="false" aria-controls="sp-root" tabindex="-1"><?= icon('sliders-horizontal', 'icon-sm') ?><span>Assistant</span></button>
  <button type="button" role="tab" id="sp-tab-ai" data-tab="ai" aria-selected="false" aria-controls="sp-root" tabindex="-1" hidden><?= icon('bot', 'icon-sm') ?><span>AI provider</span></button>
</div>
<section class="panel toolbar" id="sp-toolbar" aria-label="Filters" hidden></section>
<div id="sp-root" role="tabpanel" aria-live="polite" aria-busy="true" aria-labelledby="sp-tab-conversations"><div class="skeleton h-72"></div></div>
