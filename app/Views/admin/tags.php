<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Tags & Badges',
    'sub' => 'The coloured badges shown at the top of service cards. Assign one to any service in <a class="link" href="' . e(url('/admin/services')) . '">Services</a>; <b>TRIAL PACK</b> appears automatically on every service priced at exactly ₹5.',
    'actions' => '<button type="button" class="btn btn-primary btn-shine" id="add-tag">' . icon('plus') . '<span>New tag</span></button>',
]) ?>
<?= View::partial('admin-subnav', ['active' => 'tags']) ?>
<div id="tags-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>

<dialog class="dlg dlg-modal dlg-form" id="tag-dialog" aria-labelledby="td-title">
  <div class="dlg-panel">
    <div class="dlg-head">
      <div class="min-w-0"><h2 id="td-title" class="text-lg font-extrabold truncate">Tag</h2><p class="text-xs text-muted" id="td-sub"></p></div>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-dialog-close aria-label="Close editor"><?= icon('x') ?></button>
    </div>
    <div class="dlg-body" id="td-body"></div>
    <div class="dlg-foot" id="td-foot"></div>
  </div>
</dialog>
