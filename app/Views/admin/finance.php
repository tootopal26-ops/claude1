<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Finance',
    'sub' => 'What the shop earned and what it cost. Revenue is verified payments only; a cost that is not known is labelled, never counted as ₹0. Times are shown in <span data-tz-label>your business time zone</span>.',
    'actions' => '<button type="button" class="btn btn-primary" id="fin-add" hidden>' . icon('plus', 'icon-sm') . '<span>Record expense</span></button>',
]) ?>
<div id="fin-status" class="mb-4" aria-live="polite"></div>
<div class="seg mb-4" role="tablist" aria-label="Finance sections" id="fin-tabs">
  <button type="button" role="tab" id="fin-tab-overview" data-tab="overview" aria-selected="true" aria-controls="fin-root"><?= icon('chart-column', 'icon-sm') ?><span>Profit</span></button>
  <button type="button" role="tab" id="fin-tab-services" data-tab="services" aria-selected="false" aria-controls="fin-root" tabindex="-1"><?= icon('layers', 'icon-sm') ?><span>Services</span></button>
  <button type="button" role="tab" id="fin-tab-costs" data-tab="costs" aria-selected="false" aria-controls="fin-root" tabindex="-1"><?= icon('receipt', 'icon-sm') ?><span>Expenses</span></button>
  <button type="button" role="tab" id="fin-tab-reconcile" data-tab="reconcile" aria-selected="false" aria-controls="fin-root" tabindex="-1"><?= icon('list-checks', 'icon-sm') ?><span>Reconciliation</span><span class="tab-count" id="fin-recon-count" hidden>0</span></button>
  <button type="button" role="tab" id="fin-tab-settings" data-tab="settings" aria-selected="false" aria-controls="fin-root" tabindex="-1"><?= icon('sliders-horizontal', 'icon-sm') ?><span>Settings</span></button>
</div>
<section class="panel dash-filters" id="fin-filters" aria-label="Filters" hidden>
  <div class="seg" role="group" aria-label="Date range" id="fin-range">
    <button type="button" data-range="today" aria-pressed="false">Today</button>
    <button type="button" data-range="7d" aria-pressed="false">7 days</button>
    <button type="button" data-range="30d" aria-pressed="false">30 days</button>
    <button type="button" data-range="90d" aria-pressed="false">90 days</button>
    <button type="button" data-range="lifetime" aria-pressed="false">Lifetime</button>
    <button type="button" data-range="custom" aria-pressed="false"><?= icon('calendar', 'icon-sm') ?>Custom</button>
  </div>
  <div class="dash-custom" id="fin-custom" hidden>
    <label class="sr-only" for="fin-from">From date</label>
    <input class="input input-sm" type="date" id="fin-from">
    <span class="text-muted text-sm" aria-hidden="true">to</span>
    <label class="sr-only" for="fin-to">To date</label>
    <input class="input input-sm" type="date" id="fin-to">
    <button type="button" class="btn btn-sm btn-primary" id="fin-apply">Apply</button>
  </div>
  <div class="dash-selects">
    <label class="sr-only" for="fin-platform">Platform</label>
    <select class="select select-sm" id="fin-platform"><option value="">All platforms</option></select>
    <label class="sr-only" for="fin-service">Service</label>
    <select class="select select-sm" id="fin-service"><option value="">All services</option></select>
    <label class="sr-only" for="fin-mode">Order type</label>
    <select class="select select-sm" id="fin-mode"><option value="">Auto + manual</option><option value="AUTO">Auto only</option><option value="MANUAL">Manual only</option></select>
    <label class="sr-only" for="fin-payment">Payment status</label>
    <select class="select select-sm" id="fin-payment">
      <option value="">All payments</option><option value="success">Successful</option><option value="pending">Pending</option>
      <option value="failed">Failed</option><option value="expired">Expired</option><option value="refunded">Refunded</option>
    </select>
    <button type="button" class="btn btn-sm btn-ghost" id="fin-reset" hidden>Reset</button>
  </div>
</section>
<div id="fin-root" role="tabpanel" aria-live="polite" aria-busy="true" aria-labelledby="fin-tab-overview"><div class="skeleton h-72"></div></div>
