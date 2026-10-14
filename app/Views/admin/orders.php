<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Orders',
    'sub' => 'Every order from payment to delivery. Open an order to see its payment, provider calls and full timeline.',
    'actions' => '<button type="button" class="btn btn-success btn-shine" id="approve-all" disabled>' . icon('square-check-big') . '<span>APPROVE ALL</span><span class="approve-badge" id="approve-all-count" hidden>0</span></button>',
]) ?>

<section class="panel toolbar" aria-label="Order filters">
  <div class="chips" id="order-chips" role="group" aria-label="Quick filters"></div>
  <div class="toolbar-row">
    <div class="search">
      <?= icon('search', 'icon-sm') ?>
      <label class="sr-only" for="o-search">Search orders</label>
      <input class="input input-sm" id="o-search" type="search" placeholder="Search order ID, username or link, UTR, provider order…" maxlength="100" autocomplete="off">
    </div>
    <label class="sr-only" for="o-platform">Platform</label>
    <select class="select select-sm" id="o-platform"><option value="">All platforms</option></select>
    <label class="sr-only" for="o-mode">Delivery mode</label>
    <select class="select select-sm" id="o-mode"><option value="">Auto + manual</option><option value="AUTO">Auto</option><option value="MANUAL">Manual</option></select>
    <label class="sr-only" for="o-status">Status</label>
    <select class="select select-sm" id="o-status"><option value="">Any status</option></select>
    <label class="sr-only" for="o-from">From date</label>
    <input class="input input-sm" type="date" id="o-from">
    <label class="sr-only" for="o-to">To date</label>
    <input class="input input-sm" type="date" id="o-to">
    <button type="button" class="btn btn-sm btn-ghost" id="o-reset" hidden>Reset</button>
  </div>
</section>

<div id="orders-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>

<dialog class="dlg dlg-drawer" id="order-drawer" aria-labelledby="od-title">
  <div class="dlg-panel">
    <div class="dlg-head">
      <div class="min-w-0"><h2 id="od-title" class="text-lg font-extrabold truncate">Order</h2><p class="text-xs text-muted" id="od-sub"></p></div>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-dialog-close aria-label="Close order details"><?= icon('x') ?></button>
    </div>
    <div class="dlg-body" id="od-body"></div>
  </div>
</dialog>
