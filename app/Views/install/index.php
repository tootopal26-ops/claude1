<?php
/** @var bool $unlocked @var string $csrf @var string $tokenPath @var array $requirements @var bool $canContinue @var array $defaults @var array $zones @var string $root @var string $php */
$steps = $unlocked ? ['server' => 'Server', 'database' => 'Database', 'site' => 'Your site', 'admin' => 'Admin', 'review' => 'Install'] : ['unlock' => 'Unlock'];
?>
<section class="install-wrap">
  <div class="install-card glass-strong rgb-ring" id="install-card">
    <header class="install-head">
      <span class="install-logo rgb-ring"><img src="<?= e(asset('img/logo-mark.webp')) ?>" width="56" height="56" alt=""></span>
      <div>
        <h1>Install SOCIAL MEDIA BOOSTER</h1>
        <p>Takes about two minutes. You will need your MySQL database details.</p>
      </div>
    </header>

    <ol class="install-steps" aria-label="Installation progress" id="steps">
      <?php $n = 0; foreach ($steps as $key => $label): $n++; ?>
      <li data-for="<?= e($key) ?>"<?= $n === 1 ? ' aria-current="step"' : '' ?>><span class="dot"><?= $n ?></span><span class="lbl"><?= e($label) ?></span></li>
      <?php endforeach; ?>
    </ol>

    <div id="install-alert" class="callout callout-error" role="alert" hidden><?= icon('circle-alert') ?><div id="install-alert-text"></div></div>

<?php if (!$unlocked): ?>
    <section data-step="unlock" aria-labelledby="h-unlock">
      <h2 id="h-unlock" class="install-h">Confirm it is you</h2>
      <p class="install-p">To stop strangers from installing on your domain first, the installer asks for a one-time token that only the server owner can read.</p>
      <ol class="install-list">
        <li>Open <strong>cPanel → File Manager</strong> (or FTP) and go to the project folder.</li>
        <li>Open <code class="kbd"><?= e($tokenPath) ?></code> and copy the text inside.</li>
        <li>Paste it below.</li>
      </ol>
      <form id="unlock-form" novalidate autocomplete="off">
        <div class="field"><label class="label req" for="token">Installation token</label>
          <input class="input mono" id="token" name="token" autocomplete="off" spellcheck="false" autocapitalize="none" maxlength="80" autofocus></div>
        <div class="install-nav"><button type="submit" class="btn btn-primary btn-lg btn-shine" id="unlock-btn"><span>Continue</span></button></div>
      </form>
    </section>
<?php else: ?>
    <section data-step="server" aria-labelledby="h-server">
      <h2 id="h-server" class="install-h">Server check</h2>
      <p class="install-p">PHP <?= e($php) ?> on <?= e(PHP_OS_FAMILY) ?>. Everything marked required must be green.</p>
      <ul class="health install-health">
        <?php foreach ($requirements as $r): ?>
        <li class="health-item <?= $r['ok'] ? 'ok' : ($r['required'] ? 'bad' : 'ok') ?>"><?= icon($r['ok'] ? 'circle-check' : ($r['required'] ? 'circle-alert' : 'triangle-alert')) ?>
          <span><b><?= e($r['label']) ?></b><br><small class="text-muted"><?= e($r['detail']) ?></small></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="install-nav">
        <a class="btn" href="<?= e(url('/install')) ?>">Check again</a>
        <button type="button" class="btn btn-primary btn-lg" data-next <?= $canContinue ? '' : 'disabled' ?>><span>Continue</span></button>
      </div>
    </section>

    <section data-step="database" aria-labelledby="h-db" hidden>
      <h2 id="h-db" class="install-h">Database</h2>
      <p class="install-p">Create an empty MySQL database and a user in <strong>cPanel → MySQL® Databases</strong>, add the user to the database with <em>ALL PRIVILEGES</em>, then enter the details here.</p>
      <form data-form="database" novalidate autocomplete="off">
        <div class="field-row"><div class="field"><label class="label req" for="db_host">Host</label><input class="input" id="db_host" name="db_host" value="<?= e($defaults['db_host']) ?>" spellcheck="false" autocapitalize="none"></div>
          <div class="field"><label class="label req" for="db_port">Port</label><input class="input" id="db_port" name="db_port" inputmode="numeric" value="<?= e($defaults['db_port']) ?>"></div></div>
        <div class="field"><label class="label req" for="db_name">Database name</label><input class="input" id="db_name" name="db_name" placeholder="cpuser_smm" spellcheck="false" autocapitalize="none"></div>
        <div class="field-row"><div class="field"><label class="label req" for="db_user">Database user</label><input class="input" id="db_user" name="db_user" placeholder="cpuser_smm" spellcheck="false" autocapitalize="none" autocomplete="off"></div>
          <div class="field"><label class="label" for="db_pass">Password</label><div class="input-icon"><?= icon('lock') ?><input class="input pw-input" id="db_pass" name="db_pass" type="password" autocomplete="new-password"><button type="button" class="pw-toggle" data-toggle="db_pass" aria-label="Show password" aria-pressed="false"><span data-eye><?= icon('eye') ?></span><span data-eye-off hidden><?= icon('eye-off') ?></span></button></div></div></div>
        <div id="db-result" class="mt-3" aria-live="polite"></div>
        <div class="install-nav"><button type="button" class="btn" data-back>Back</button>
          <button type="button" class="btn" id="test-db"><?= icon('activity', 'icon-sm') ?><span>Test connection</span></button>
          <button type="submit" class="btn btn-primary btn-lg"><span>Continue</span></button></div>
      </form>
    </section>

    <section data-step="site" aria-labelledby="h-site" hidden>
      <h2 id="h-site" class="install-h">Your site</h2>
      <form data-form="site" novalidate autocomplete="off">
        <div class="field"><label class="label req" for="url">Website address</label><input class="input mono" id="url" name="url" value="<?= e($defaults['url']) ?>" spellcheck="false" autocapitalize="none">
          <p class="field-hint">Exactly how customers will open the site, without a trailing slash. Use <strong>https://</strong> on a real domain — payment gateways and secure cookies need it.</p></div>
        <div class="field"><label class="label req" for="site_name">Website name</label><input class="input" id="site_name" name="site_name" value="<?= e($defaults['site_name']) ?>" maxlength="60"></div>
        <div class="field"><label class="label" for="timezone">Business time zone</label>
          <select class="select" id="timezone" name="timezone"><?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= $z === $defaults['timezone'] ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select>
          <p class="field-hint">Daily reports and “today” use this zone. It locks after your first order.</p></div>
        <div class="install-nav"><button type="button" class="btn" data-back>Back</button><button type="submit" class="btn btn-primary btn-lg"><span>Continue</span></button></div>
      </form>
    </section>

    <section data-step="admin" aria-labelledby="h-admin" hidden>
      <h2 id="h-admin" class="install-h">Your admin account</h2>
      <p class="install-p">This account has full control of the shop. Use a strong password you do not use anywhere else.</p>
      <form data-form="admin" novalidate autocomplete="off">
        <div class="field-row"><div class="field"><label class="label req" for="admin_username">Username</label><input class="input" id="admin_username" name="admin_username" autocomplete="username" spellcheck="false" autocapitalize="none" maxlength="64"></div>
          <div class="field"><label class="label req" for="admin_email">Email</label><input class="input" id="admin_email" name="admin_email" type="email" autocomplete="email" spellcheck="false" maxlength="190"></div></div>
        <div class="field"><label class="label req" for="admin_password">Password</label><div class="input-icon"><?= icon('lock') ?><input class="input pw-input" id="admin_password" name="admin_password" type="password" autocomplete="new-password" maxlength="128"><button type="button" class="pw-toggle" data-toggle="admin_password" aria-label="Show password" aria-pressed="false"><span data-eye><?= icon('eye') ?></span><span data-eye-off hidden><?= icon('eye-off') ?></span></button></div></div>
        <div class="field"><label class="label req" for="admin_confirm">Confirm password</label><div class="input-icon"><?= icon('lock') ?><input class="input pw-input" id="admin_confirm" name="admin_confirm" type="password" autocomplete="new-password" maxlength="128"></div></div>
        <div class="meter mt-3" id="pw-meter" data-level="0" role="img" aria-label="Password strength"><span></span></div>
        <ul class="reqs" id="pw-rules"><li data-rule="len"><?= icon('circle-check') ?><span>At least 12 characters</span></li><li data-rule="lower"><?= icon('circle-check') ?><span>A lowercase letter</span></li><li data-rule="upper"><?= icon('circle-check') ?><span>An uppercase letter</span></li><li data-rule="num"><?= icon('circle-check') ?><span>A number</span></li><li data-rule="sym"><?= icon('circle-check') ?><span>A symbol (! @ # $ %)</span></li><li data-rule="match"><?= icon('circle-check') ?><span>Both passwords match</span></li></ul>
        <div class="install-nav"><button type="button" class="btn" data-back>Back</button><button type="submit" class="btn btn-primary btn-lg"><span>Continue</span></button></div>
      </form>
    </section>

    <section data-step="review" aria-labelledby="h-review" hidden>
      <h2 id="h-review" class="install-h">Ready to install</h2>
      <div class="install-summary" id="summary"></div>
      <p class="install-p">The installer will save your settings in <code class="kbd">.env</code>, create the database tables, add the default Instagram and YouTube services and create your admin account.</p>
      <div id="run-progress" class="install-progress" hidden aria-live="polite"><span class="spinner"></span><span id="run-status">Starting…</span></div>
      <div id="env-help" hidden>
        <div class="callout callout-warning"><?= icon('triangle-alert') ?><div><strong>Create the .env file by hand.</strong> The installer cannot write to the project folder. In File Manager create a file named <code class="kbd">.env</code> next to the <code class="kbd">public</code> folder, paste this text into it, save, then press Install again.</div></div>
        <label class="sr-only" for="env-text">Contents for the .env file</label><textarea class="textarea mono mt-2" id="env-text" rows="12" readonly></textarea>
        <button type="button" class="btn btn-sm mt-2" id="copy-env"><?= icon('copy', 'icon-sm') ?><span>Copy</span></button>
      </div>
      <div class="install-nav"><button type="button" class="btn" data-back id="review-back">Back</button><button type="button" class="btn btn-success btn-lg btn-shine" id="run-install"><?= icon('rocket') ?><span>Install now</span></button></div>
    </section>

    <section data-step="done" aria-labelledby="h-done" hidden>
      <div class="install-done"><span class="done-badge"><?= icon('check', 'icon-xl') ?></span>
        <h2 id="h-done" class="install-h">Installed!</h2>
        <p class="install-p">Your shop is ready. Sign in to the admin panel to finish setting up.</p></div>
      <div id="done-warn" class="callout callout-warning mb-3" hidden><?= icon('triangle-alert') ?><div><strong>Your site address is not HTTPS.</strong> Payment gateways and secure cookies need https://. Install an SSL certificate (cPanel → SSL/TLS Status → Run AutoSSL), then change <code class="kbd">APP_URL</code> in <code class="kbd">.env</code> to your https:// address.</div></div>
      <ol class="install-list" id="done-list"></ol>
      <div class="install-nav"><a class="btn btn-primary btn-lg btn-shine" id="done-login" href="<?= e(url('/admin/login')) ?>"><span>Go to admin sign-in</span></a></div>
    </section>
<?php endif; ?>
  </div>
  <p class="install-foot">Installer token and lock files are removed automatically when installation finishes.</p>
</section>
