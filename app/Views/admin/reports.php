<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Daily reports',
    'sub' => 'One report for every finished business day, calculated from the shop’s own records by fixed rules — each figure says whether it is <strong>measured</strong>, <strong>estimated</strong>, <strong>incomplete</strong> or <strong>unavailable</strong>. Days run midnight to midnight in <span data-tz-label>your business time zone</span>.',
]) ?>
<div id="rp-status" class="mb-4" aria-live="polite"></div>
<section class="panel toolbar" id="rp-toolbar" aria-label="Filters" hidden>
  <div class="toolbar-row">
    <label class="sr-only" for="rp-from">From date</label>
    <input class="input input-sm" type="date" id="rp-from">
    <span class="text-muted text-sm" aria-hidden="true">to</span>
    <label class="sr-only" for="rp-to">To date</label>
    <input class="input input-sm" type="date" id="rp-to">
    <button type="button" class="btn btn-sm btn-primary" id="rp-apply">Apply</button>
    <button type="button" class="btn btn-sm btn-ghost" id="rp-reset" hidden>Reset</button>
  </div>
</section>
<div id="rp-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>
