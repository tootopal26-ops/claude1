<?php
/** Page heading used by every admin page. Expects: $h1, $sub (raw HTML allowed), optional $actions (raw HTML). */
?>
<div class="page-head">
  <div class="min-w-0">
    <h1 class="page-title" id="page-title" tabindex="-1"><?= e($h1) ?></h1>
    <p class="page-sub"><?= $sub ?></p>
  </div>
  <?php if (!empty($actions)): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
</div>
