<?php
/** @var array $s service card (Catalog::card), @var array $p platform */
$id = (int) $s['id'];
$typeIcon = match ($s['type']) {
    'followers' => 'user-plus', 'likes' => 'heart-fill', 'views' => 'eye', 'verification' => 'verified',
    'subscribers' => 'bell-ring', 'watch_time' => 'clock', default => 'sparkles',
};
$badgeColor = in_array($s['badge_color'], \App\Support\Status::BADGE_COLORS, true) ? $s['badge_color'] : 'red';
$classes = 'svc glass' . ($s['is_premium'] ? ' svc-premium svc-wide' : '');
?>
<article class="<?= e($classes) ?>" data-service data-id="<?= $id ?>" data-platform="<?= e($p['slug']) ?>" data-kind="<?= e($s['target_kind']) ?>" data-title="<?= e($s['title']) ?>" data-price="<?= e($s['price']) ?>" data-open="false">
  <?php if ($s['badge'] !== null): ?><span class="badge badge-<?= e($badgeColor) ?> svc-badge"><?= e($s['badge']) ?></span><?php endif; ?>
  <div class="svc-top">
    <div class="svc-icon" data-platform="<?= e($p['slug']) ?>" data-type="<?= e($s['type']) ?>">
      <?php if ($s['icon_url']): ?>
        <img class="svc-img" src="<?= e($s['icon_url']) ?>" alt="" width="48" height="48" loading="lazy" decoding="async">
      <?php else: ?>
        <?= icon($typeIcon, 'icon' . ($typeIcon === 'heart-fill' ? ' icon-fill' : '')) ?>
      <?php endif; ?>
      <span class="svc-chip"><img src="<?= e($p['logo_url']) ?>" alt="" width="22" height="22" loading="lazy" decoding="async"></span>
    </div>
    <div class="svc-body">
      <h4 class="svc-title"><?= e($s['title']) ?></h4>
      <?php if ($s['subtitle'] !== ''): ?><p class="svc-sub"><?= e($s['subtitle']) ?></p><?php endif; ?>
    </div>
    <div class="svc-price"><b class="num"><?= e($s['price']) ?></b></div>
  </div>
  <button type="button" class="btn btn-success btn-lg btn-block btn-shine" data-action="buy" aria-expanded="false" aria-controls="order-<?= $id ?>">BUY NOW</button>
  <div class="expand" id="order-<?= $id ?>" data-open="false" inert>
    <div class="expand-inner">
      <form class="order-form" novalidate autocomplete="off">
        <label class="label" for="target-<?= $id ?>">Enter username or link</label>
        <div class="input-wrap mt-2">
          <?= icon('link') ?>
          <input id="target-<?= $id ?>" class="input" type="text" name="target" inputmode="url" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"
                 maxlength="300" placeholder="<?= e($s['placeholder']) ?>" aria-describedby="msg-<?= $id ?>" aria-required="true">
        </div>
        <p class="field-msg" id="msg-<?= $id ?>" data-state="idle" role="status" aria-live="polite"><?= e($s['hint']) ?></p>
        <div class="mt-2 grid gap-2">
          <button type="submit" class="btn btn-success btn-lg btn-block btn-shine" data-action="proceed" disabled>PROCEED TO PAYMENT</button>
          <button type="button" class="btn btn-ghost btn-sm justify-self-center<?= $s['is_premium'] ? ' text-white' : '' ?>" data-action="cancel">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</article>
