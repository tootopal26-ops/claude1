<?php use App\Core\View; ?>
<?= View::partial('admin-head', ['h1' => 'System', 'sub' => 'Server health, background tasks and deployment checks.']) ?>
<div id="system-root" aria-live="polite" aria-busy="true"><div class="skeleton h-72"></div></div>
