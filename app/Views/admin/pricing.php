<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Adaptive pricing',
    'sub' => 'A deterministic pricing engine: it may show a different price to a test cohort, never by itself — every experiment starts as a dry run (same price to both cohorts, a safety rehearsal) and only an owner can switch it to live. Rollback triggers run automatically. AI, where configured, only ever explains — see <strong>Learning history</strong>.',
]) ?>
<div id="pr-summary" class="mb-5" aria-live="polite" aria-busy="true">
  <div class="kpi-grid" aria-hidden="true"><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div></div>
</div>
<div class="seg seg-compact mb-4" role="tablist" aria-label="Adaptive pricing sections" id="pr-tabs">
  <button type="button" role="tab" id="pr-tab-experiments" data-tab="experiments" aria-selected="true" aria-controls="pr-root"><?= icon('sliders-horizontal', 'icon-sm') ?><span>Experiments</span></button>
  <button type="button" role="tab" id="pr-tab-simulator" data-tab="simulator" aria-selected="false" aria-controls="pr-root" tabindex="-1"><?= icon('flask-conical', 'icon-sm') ?><span>Simulator</span></button>
  <button type="button" role="tab" id="pr-tab-guard" data-tab="guard" aria-selected="false" aria-controls="pr-root" tabindex="-1"><?= icon('shield-alert', 'icon-sm') ?><span>Profit Guard</span></button>
  <button type="button" role="tab" id="pr-tab-history" data-tab="history" aria-selected="false" aria-controls="pr-root" tabindex="-1"><?= icon('history', 'icon-sm') ?><span>Learning history</span></button>
</div>
<section class="panel toolbar" id="pr-toolbar" aria-label="Filters and actions" hidden></section>
<div id="pr-root" role="tabpanel" aria-live="polite" aria-busy="true" aria-labelledby="pr-tab-experiments"><div class="skeleton h-72"></div></div>
