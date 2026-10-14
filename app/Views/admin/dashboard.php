<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Dashboard',
    'sub' => 'Revenue, profit, orders and visitors at a glance. Times are shown in <span data-tz-label>your business time zone</span>.',
    'actions' => '<button type="button" class="btn btn-sm" data-action="refresh">' . icon('refresh-cw', 'icon-sm') . '<span>Refresh</span></button>',
]) ?>

<section class="panel dash-filters" aria-label="Dashboard filters">
  <div class="seg" role="group" aria-label="Date range" id="range-seg">
    <button type="button" data-range="today" aria-pressed="false">Today</button>
    <button type="button" data-range="7d" aria-pressed="false">7 days</button>
    <button type="button" data-range="30d" aria-pressed="false">30 days</button>
    <button type="button" data-range="90d" aria-pressed="false">90 days</button>
    <button type="button" data-range="lifetime" aria-pressed="false">Lifetime</button>
    <button type="button" data-range="custom" aria-pressed="false"><?= icon('calendar', 'icon-sm') ?>Custom</button>
  </div>
  <div class="dash-custom" id="custom-range" hidden>
    <label class="sr-only" for="f-from">From date</label>
    <input class="input input-sm" type="date" id="f-from">
    <span class="text-muted text-sm" aria-hidden="true">to</span>
    <label class="sr-only" for="f-to">To date</label>
    <input class="input input-sm" type="date" id="f-to">
    <button type="button" class="btn btn-sm btn-primary" id="apply-custom">Apply</button>
  </div>
  <div class="dash-selects">
    <label class="sr-only" for="f-platform">Platform</label>
    <select class="select select-sm" id="f-platform"><option value="">All platforms</option></select>
    <label class="sr-only" for="f-mode">Delivery mode</label>
    <select class="select select-sm" id="f-mode"><option value="">Auto + manual</option><option value="AUTO">Auto only</option><option value="MANUAL">Manual only</option></select>
    <label class="sr-only" for="f-payment">Payment status</label>
    <select class="select select-sm" id="f-payment">
      <option value="">All payments</option><option value="success">Successful</option><option value="pending">Pending</option>
      <option value="failed">Failed</option><option value="expired">Expired</option><option value="refunded">Refunded</option>
    </select>
    <button type="button" class="btn btn-sm btn-ghost" id="reset-filters" hidden>Reset</button>
  </div>
</section>

<div id="dash-root" aria-live="polite" aria-busy="true">
  <div class="kpi-grid"><?php for ($i = 0; $i < 7; $i++): ?><div class="skeleton kpi-skel"></div><?php endfor; ?></div>
</div>
