<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'SMM API',
    'sub' => 'Provider connection for automatic delivery. The API key stays on the server — it is never sent to a browser.',
    'actions' => '<button type="button" class="btn btn-primary btn-shine" data-add>' . icon('plus') . '<span>Add provider</span></button>',
]) ?>
<div id="smm-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>

<dialog class="dlg dlg-modal dlg-form" id="prov-dialog" aria-labelledby="pd-title">
  <div class="dlg-panel">
    <div class="dlg-head"><h2 id="pd-title" class="text-lg font-extrabold">SMM provider</h2>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-dialog-close aria-label="Close"><?= icon('x') ?></button></div>
    <div class="dlg-body" id="pd-body"></div>
    <div class="dlg-foot"><button type="button" class="btn" data-dialog-close>Cancel</button>
      <button type="button" class="btn btn-primary" id="pv-save"><?= icon('check') ?><span>Save provider</span></button></div>
  </div>
</dialog>
