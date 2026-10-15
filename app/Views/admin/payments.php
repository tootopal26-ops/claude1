<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Payments',
    'sub' => 'Gateway payments and webhook deliveries. An order is only paid from the gateway’s signed notification or our own status lookup, for exactly the order’s amount — see <a href="' . e(url('/admin/payment-checks')) . '">Payment checks</a> for flags and re-checks.',
]) ?>
<section class="panel toolbar" aria-label="Payment filters">
  <div class="chips" id="pay-chips" role="group" aria-label="Payment status"></div>
  <div class="toolbar-row">
    <div class="search">
      <?= icon('search', 'icon-sm') ?>
      <label class="sr-only" for="p-search">Search payments</label>
      <input class="input input-sm" id="p-search" type="search" placeholder="Search order ID, gateway order ID or UTR…" maxlength="60" autocomplete="off">
    </div>
  </div>
</section>
<div id="payments-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>
