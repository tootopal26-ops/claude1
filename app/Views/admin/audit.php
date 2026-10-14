<?php use App\Core\View; ?>
<?= View::partial('admin-head', ['h1' => 'Audit log', 'sub' => 'A permanent record of sensitive admin actions: who did what, when and from where. Secrets are never written here.']) ?>
<section class="panel toolbar" aria-label="Audit filters">
  <div class="chips" id="audit-chips" role="group" aria-label="Category"></div>
  <div class="toolbar-row">
    <div class="search">
      <?= icon('search', 'icon-sm') ?>
      <label class="sr-only" for="a-search">Search the audit log</label>
      <input class="input input-sm" id="a-search" type="search" placeholder="Search action, admin, entity or detail…" maxlength="80" autocomplete="off">
    </div>
  </div>
</section>
<div id="audit-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>
