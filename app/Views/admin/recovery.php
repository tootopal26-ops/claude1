<?php use App\Core\View; ?>
<?= View::partial('admin-head', [
    'h1' => 'Order recovery',
    'sub' => 'Paid orders that are not where they should be — waiting to be sent, retrying, with an unknown provider outcome, failed or not followed — and what you can safely do about each. An order the provider may already have is never resent by itself.',
    'actions' => '<button type="button" class="btn btn-ghost" id="rc-dry" hidden>' . icon('eye', 'icon-sm') . '<span>Dry run</span></button>'
        . '<button type="button" class="btn" id="rc-run" hidden>' . icon('refresh-cw', 'icon-sm') . '<span>Run recovery now</span></button>',
]) ?>
<div id="rc-summary" class="mb-5" aria-live="polite" aria-busy="true">
  <div class="kpi-grid" aria-hidden="true"><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div><div class="skeleton kpi-skel"></div></div>
</div>
<div class="seg mb-4" role="tablist" aria-label="Order recovery sections" id="rc-tabs">
  <button type="button" role="tab" id="rc-tab-queue" data-tab="queue" aria-selected="true" aria-controls="rc-root"><?= icon('life-buoy', 'icon-sm') ?><span>Queue</span></button>
  <button type="button" role="tab" id="rc-tab-policy" data-tab="policy" aria-selected="false" aria-controls="rc-root" tabindex="-1"><?= icon('sliders-horizontal', 'icon-sm') ?><span>Retry policy</span></button>
</div>
<section class="panel toolbar" id="rc-toolbar" aria-label="Filters"></section>
<div id="rc-root" role="tabpanel" aria-live="polite" aria-busy="true" aria-labelledby="rc-tab-queue"><div class="skeleton h-72"></div></div>
