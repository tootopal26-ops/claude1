<?php use App\Core\View; ?>
<?= View::partial('admin-head', ['h1' => 'Payment gateway', 'sub' => 'Connect the payment gateway customers pay through. Keys are stored encrypted and are never shown again.']) ?>
<div id="gateway-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>
