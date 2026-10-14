<?php /** @var array $site @var string $csrf @var ?array $notice @var string $next */ ?>
<section class="login-wrap">
  <div class="login-card glass-strong rgb-ring" id="login-card">
    <div class="login-logo rgb-ring"><img src="<?= e($site['logo_url']) ?>" width="72" height="72" alt="" decoding="async"></div>
    <h1 class="login-title">Admin sign in</h1>
    <p class="login-sub">Welcome back. Sign in to manage <strong><?= e($site['name']) ?></strong>.</p>

    <div id="login-alert" class="callout <?= $notice ? 'callout-' . e($notice['type']) : '' ?>" role="alert" aria-live="assertive"<?= $notice ? '' : ' hidden' ?>>
      <?= icon($notice && $notice['type'] === 'success' ? 'circle-check' : 'circle-alert') ?>
      <span id="login-alert-text"><?= $notice ? e($notice['text']) : '' ?></span>
    </div>

    <form id="login-form" method="post" action="<?= e(url('/admin/login')) ?>" novalidate>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">

      <div class="field">
        <label class="label" for="username">Username or email</label>
        <div class="input-icon">
          <?= icon('user') ?>
          <input class="input" id="username" name="username" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="190" required autofocus>
        </div>
      </div>

      <div class="field">
        <label class="label" for="password">Password</label>
        <div class="input-icon">
          <?= icon('lock') ?>
          <input class="input pw-input" id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required>
          <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Show password" aria-pressed="false" aria-controls="password">
            <span data-eye><?= icon('eye') ?></span><span data-eye-off hidden><?= icon('eye-off') ?></span>
          </button>
        </div>
        <p class="field-hint" id="caps-hint" hidden>Caps Lock is on.</p>
      </div>

      <label class="check">
        <input type="checkbox" name="remember" value="1" id="remember">
        <span>Keep me signed in on this device</span>
      </label>

      <button class="btn btn-primary btn-lg btn-block btn-shine" type="submit" id="login-submit"><span>Sign in</span></button>
    </form>

    <p class="login-note"><?= icon('shield-check', 'icon-sm') ?> Protected area — sign-in attempts are rate limited and logged.</p>
  </div>
  <a class="login-back" href="<?= e(url('/')) ?>"><?= icon('arrow-left', 'icon-sm') ?> Back to store</a>
</section>
