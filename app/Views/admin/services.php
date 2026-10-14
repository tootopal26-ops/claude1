<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Services',
    'sub' => 'Prices, delivery mode and provider costs. Customers only ever see the public price — profit is calculated from the cost you enter here.',
    'actions' => '<button type="button" class="btn btn-primary btn-shine" id="add-service">' . icon('plus') . '<span>Add service</span></button>',
]) ?>
<div id="services-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>

<dialog class="dlg dlg-drawer dlg-wide" id="service-drawer" aria-labelledby="sd-title">
  <div class="dlg-panel">
    <div class="dlg-head">
      <div class="min-w-0"><h2 id="sd-title" class="text-lg font-extrabold truncate">Service</h2><p class="text-xs text-muted" id="sd-sub"></p></div>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-dialog-close aria-label="Close editor"><?= icon('x') ?></button>
    </div>
    <div class="dlg-body" id="sd-body"></div>
    <div class="dlg-foot" id="sd-foot"></div>
  </div>
</dialog>
