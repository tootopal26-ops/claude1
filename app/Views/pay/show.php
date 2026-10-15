<?php
/**
 * @var array $site
 * @var array $pay   public subset: ref, state, amount, amount_paise, service, order_id, expires_at, server_now, qr_url, download_url, pay_url, track_url, instructions
 */
$state = $pay['state'];
?>
<div class="pay-wrap">
  <div class="pay-card glass-strong" id="pay-root" data-ref="<?= e($pay['ref']) ?>" data-state="<?= e($state) ?>">

    <!-- ───────── pending ─────────
         upi    : a real UPI payment request for exactly this order → a QR a UPI app can scan straight into its payment screen
         hosted : the gateway only offers its own checkout page → no QR here (a QR of that page would open it in a browser, which
                  draws a second QR): one button opens the checkout, which has the QR / UPI-app choice ───────── -->
    <?php $upi = $pay['mode'] === 'upi'; $live = $state === 'pending'; /* an expired / paid / failed / under-review session never ships its QR or payment link */ ?>
    <section data-view="pending" data-mode="<?= e($pay['mode']) ?>"<?= $state === 'pending' ? '' : ' hidden' ?> aria-live="polite">
      <p class="pay-kicker"><?= icon('shield-check', 'icon icon-sm') ?> Secure payment</p>
      <h1 class="pay-title"><?= $upi ? 'SCAN AND PAY' : 'PAY SECURELY' ?></h1>
      <p class="pay-summary"><b class="num"><?= e($pay['amount']) ?></b><span><?= e($pay['service']) ?></span></p>

      <?php if ($upi): ?>
      <div class="qr-frame rgb-ring" id="qr-frame" data-dead="<?= $live ? 'false' : 'true' ?>">
        <img id="qr-img"<?= $live ? ' src="' . e($pay['qr_url']) . '"' : '' ?> width="280" height="280" alt="UPI payment QR code for <?= e($pay['amount']) ?>" decoding="async">
        <span class="qr-corner tl"></span><span class="qr-corner tr"></span><span class="qr-corner bl"></span><span class="qr-corner br"></span>
        <div class="qr-dead"><?= icon('clock', 'icon icon-xl mx-auto') ?><span>Payment session expired</span></div>
      </div>
      <p class="pay-payee" id="pay-payee">Scan with any UPI app<?php if ($pay['payee'] !== null): ?> · paying <b><?= e($pay['payee']['name'] ?? $pay['payee']['vpa']) ?></b><?php if (!empty($pay['payee']['name'])): ?> <span class="mono"><?= e($pay['payee']['vpa']) ?></span><?php endif; ?><?php endif; ?></p>
      <?php else: ?>
      <div class="hosted-card" id="hosted-frame" data-dead="<?= $live ? 'false' : 'true' ?>">
        <span class="hosted-icon" aria-hidden="true"><?= icon('shield-check') ?></span>
        <p class="hosted-lead">You finish paying on our payment partner’s secure page</p>
        <ol class="hosted-steps">
          <li><b aria-hidden="true">1</b><span>Tap <strong>PAY <?= e(strtoupper($pay['amount'])) ?> NOW</strong>. The secure payment page opens in a new tab.</span></li>
          <li><b aria-hidden="true">2</b><span>On that page, scan its QR code with any UPI app — or tap your UPI app.</span></li>
          <li><b aria-hidden="true">3</b><span>Come back to this page. We confirm your payment automatically.</span></li>
        </ol>
        <div class="qr-dead"><?= icon('clock', 'icon icon-xl mx-auto') ?><span>Payment session expired</span></div>
      </div>
      <?php endif; ?>

      <div class="timer" id="timer" data-level="ok" role="timer" aria-label="Time left to pay">
        <div class="timer-label">Time left to pay</div>
        <div class="timer-value" id="timer-value" aria-live="off">05:00</div>
        <div class="timer-bar"><i id="timer-bar"></i></div>
      </div>

      <div class="pay-actions">
        <?php if ($upi): ?>
        <a class="btn btn-lg" id="btn-download"<?= $live ? ' href="' . e($pay['download_url']) . '" download' : ' aria-disabled="true" tabindex="-1"' ?>><?= icon('download') ?> DOWNLOAD QR</a>
        <a class="btn btn-success btn-lg" id="btn-open"<?= $live ? ' href="' . e($pay['pay_url']) . '"' : ' aria-disabled="true" tabindex="-1"' ?>><?= icon('smartphone') ?> OPEN UPI APP</a>
        <?php else: ?>
        <a class="btn btn-success btn-lg btn-shine" id="btn-open"<?= $live ? ' href="' . e($pay['pay_url']) . '" target="_blank"' : ' aria-disabled="true" tabindex="-1"' ?> rel="noopener noreferrer"><?= icon('lock') ?> PAY <?= e(strtoupper($pay['amount'])) ?> NOW</a>
        <?php endif; ?>
      </div>
      <p class="pay-status" id="pay-status"><i></i><span>Waiting for your payment…</span></p>
      <p class="pay-note"><?= e($pay['instructions']) ?></p>
      <div class="pay-meta"><span>Order <b class="text-ink"><?= e($pay['order_id']) ?></b></span><span>Do not close this page</span></div>
    </section>

    <!-- ───────── verifying (customer came back from the gateway) ───────── -->
    <section data-view="verifying" hidden aria-live="polite">
      <div class="state-icon state-warn"><span class="spinner spinner-lg"></span></div>
      <h1 class="pay-title">Confirming payment…</h1>
      <p class="pay-note">We are checking with the bank. This usually takes a few seconds.</p>
    </section>

    <!-- ───────── success ───────── -->
    <section data-view="paid"<?= $state === 'paid' ? '' : ' hidden' ?> aria-live="polite">
      <div class="state-icon state-ok"><svg class="icon" aria-hidden="true"><use href="<?= e(url('assets/img/icons.svg')) ?>#i-check"/></svg></div>
      <h1 class="pay-title">Payment successful</h1>
      <p class="pay-summary"><b class="num"><?= e($pay['amount']) ?></b><span><?= e($pay['service']) ?></span></p>
      <p class="pay-note" id="paid-note">Thank you! Your order <b class="text-ink"><?= e($pay['order_id']) ?></b> has been received and is being processed.</p>
      <div class="pay-actions">
        <a class="btn btn-success btn-lg" href="<?= e($pay['track_url']) ?>" id="btn-track"><?= icon('history') ?> TRACK ORDER</a>
        <a class="btn btn-ghost" href="<?= e(url('/')) ?>">Back to services</a>
      </div>
    </section>

    <!-- ───────── expired ───────── -->
    <section data-view="expired"<?= $state === 'expired' ? '' : ' hidden' ?> aria-live="assertive">
      <div class="state-icon state-warn"><?= icon('clock') ?></div>
      <h1 class="pay-title">Payment session expired</h1>
      <p class="pay-note">The 5-minute payment window has ended and no payment was received for order <b class="text-ink"><?= e($pay['order_id']) ?></b>. <b class="text-ink">Please do not pay for this order any more.</b> Start again to get a fresh payment session.</p>
      <div class="pay-actions"><a class="btn btn-primary btn-lg" href="<?= e(url('/')) ?>#services"><?= icon('refresh-cw') ?> START AGAIN</a></div>
    </section>

    <!-- ───────── failed ───────── -->
    <section data-view="failed"<?= $state === 'failed' ? '' : ' hidden' ?> aria-live="assertive">
      <div class="state-icon state-bad"><?= icon('x') ?></div>
      <h1 class="pay-title">Payment failed</h1>
      <p class="pay-note">We could not confirm a payment for order <b class="text-ink"><?= e($pay['order_id']) ?></b>. If money was deducted, it is normally refunded by your bank automatically. You can try again below.</p>
      <div class="pay-actions"><a class="btn btn-primary btn-lg" href="<?= e(url('/')) ?>#services"><?= icon('refresh-cw') ?> TRY AGAIN</a></div>
    </section>

    <!-- ───────── needs review (amount mismatch etc.) ───────── -->
    <section data-view="review"<?= $state === 'review' ? '' : ' hidden' ?> aria-live="assertive">
      <div class="state-icon state-warn"><?= icon('triangle-alert') ?></div>
      <h1 class="pay-title">We are verifying your payment</h1>
      <p class="pay-note">Your payment for order <b class="text-ink"><?= e($pay['order_id']) ?></b> needs a quick manual check. Nothing more is needed from you — keep this order number handy<?= $site['support_link'] !== '' ? ' and contact support if it takes long' : '' ?>.</p>
      <div class="pay-actions">
        <?php if ($site['support_link'] !== ''): ?><a class="btn btn-primary btn-lg" href="<?= e($site['support_link']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('life-buoy') ?> CONTACT SUPPORT</a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/')) ?>">Back to services</a>
      </div>
    </section>

    <!-- connection trouble banner -->
    <p class="mt-4 rounded-md bg-warn-50 px-3 py-2 text-sm font-semibold text-warn-700" id="pay-offline" hidden role="alert">Connection problem — retrying…</p>
  </div>
</div>
<?= json_script('pay-data', [
    'ref' => $pay['ref'], 'state' => $state, 'expiresAt' => $pay['expires_at'], 'serverNow' => $pay['server_now'], 'ttl' => $pay['ttl'],
    'statusUrl' => $pay['status_url'], 'returned' => $pay['returned'], 'orderId' => $pay['order_id'], 'trackUrl' => $pay['track_url'],
]) ?>
