<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Business alerts',
    'sub' => 'What the Business Watchdog found. Its checks read the shop’s own records every 15 minutes and compare them with thresholds you can change — fixed rules and comparisons with recent history, <strong>not an AI</strong>. Times are shown in <span data-tz-label>your business time zone</span>.',
    'actions' => '<button type="button" class="btn" id="al-dry" hidden>' . icon('flask-conical', 'icon-sm') . '<span>Dry run</span></button>'
        . '<button type="button" class="btn btn-primary" id="al-run" hidden>' . icon('play', 'icon-sm') . '<span>Run checks now</span></button>',
]) ?>
<div id="al-summary" class="mb-5" aria-live="polite" aria-busy="true">
  <div class="kpi-grid" aria-hidden="true"><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div></div>
</div>
<div class="seg seg-compact mb-4" role="tablist" aria-label="Business alerts sections" id="al-tabs">
  <button type="button" role="tab" id="al-tab-alerts" data-tab="alerts" aria-selected="true" aria-controls="al-root"><?= icon('bell-ring', 'icon-sm') ?><span>Alerts</span></button>
  <button type="button" role="tab" id="al-tab-checks" data-tab="checks" aria-selected="false" aria-controls="al-root" tabindex="-1"><?= icon('list-checks', 'icon-sm') ?><span>Checks</span></button>
  <button type="button" role="tab" id="al-tab-settings" data-tab="settings" aria-selected="false" aria-controls="al-root" tabindex="-1"><?= icon('sliders-horizontal', 'icon-sm') ?><span>Thresholds</span></button>
  <button type="button" role="tab" id="al-tab-mail" data-tab="mail" aria-selected="false" aria-controls="al-root" tabindex="-1"><?= icon('mail', 'icon-sm') ?><span>E-mail</span></button>
</div>
<section class="panel toolbar" id="al-toolbar" aria-label="Filters"></section>
<div id="al-root" role="tabpanel" aria-live="polite" aria-busy="true" aria-labelledby="al-tab-alerts"><div class="skeleton h-72"></div></div>
