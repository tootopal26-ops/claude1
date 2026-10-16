<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Ads Intelligence',
    'sub' => 'What advertising cost, who came and who paid — per campaign. Spend is <strong>actual</strong> once you enter it and a CPC <strong>estimate</strong> until then; revenue is credit under a stated model, never “incremental revenue”. Times are shown in <span data-tz-label>your business time zone</span>.',
    'actions' => '<button type="button" class="btn" id="ads-import" hidden>' . icon('upload', 'icon-sm') . '<span>Import CSV</span></button>'
        . '<button type="button" class="btn btn-primary" id="ads-add" hidden>' . icon('plus', 'icon-sm') . '<span>Add spend</span></button>',
]) ?>
<div id="ads-status" class="mb-4" aria-live="polite"></div>
<div class="seg mb-4" role="tablist" aria-label="Ads Intelligence sections" id="ads-tabs">
  <button type="button" role="tab" id="ads-tab-overview" data-tab="overview" aria-selected="true" aria-controls="ads-root"><?= icon('chart-column', 'icon-sm') ?><span>Overview</span></button>
  <button type="button" role="tab" id="ads-tab-campaigns" data-tab="campaigns" aria-selected="false" aria-controls="ads-root" tabindex="-1"><?= icon('target', 'icon-sm') ?><span>Campaigns</span></button>
  <button type="button" role="tab" id="ads-tab-spend" data-tab="spend" aria-selected="false" aria-controls="ads-root" tabindex="-1"><?= icon('receipt', 'icon-sm') ?><span>Spend</span></button>
  <button type="button" role="tab" id="ads-tab-settings" data-tab="settings" aria-selected="false" aria-controls="ads-root" tabindex="-1"><?= icon('sliders-horizontal', 'icon-sm') ?><span>Settings</span><span class="tab-count" id="ads-missing-count" hidden>0</span></button>
</div>
<section class="panel dash-filters" id="ads-filters" aria-label="Filters" hidden>
  <div class="seg" role="group" aria-label="Date range" id="ads-range">
    <button type="button" data-range="today" aria-pressed="false">Today</button>
    <button type="button" data-range="7d" aria-pressed="false">7 days</button>
    <button type="button" data-range="30d" aria-pressed="false">30 days</button>
    <button type="button" data-range="90d" aria-pressed="false">90 days</button>
    <button type="button" data-range="lifetime" aria-pressed="false">Lifetime</button>
    <button type="button" data-range="custom" aria-pressed="false"><?= icon('calendar', 'icon-sm') ?>Custom</button>
  </div>
  <div class="dash-custom" id="ads-custom" hidden>
    <label class="sr-only" for="ads-from">From date</label>
    <input class="input input-sm" type="date" id="ads-from">
    <span class="text-muted text-sm" aria-hidden="true">to</span>
    <label class="sr-only" for="ads-to">To date</label>
    <input class="input input-sm" type="date" id="ads-to">
    <button type="button" class="btn btn-sm btn-primary" id="ads-apply">Apply</button>
  </div>
  <div class="dash-selects">
    <label class="sr-only" for="ads-campaign">Campaign</label>
    <select class="select select-sm" id="ads-campaign"><option value="">All campaigns</option></select>
    <button type="button" class="btn btn-sm btn-ghost" id="ads-reset" hidden>Reset</button>
  </div>
</section>
<div id="ads-root" role="tabpanel" aria-live="polite" aria-busy="true" aria-labelledby="ads-tab-overview"><div class="skeleton h-72"></div></div>
