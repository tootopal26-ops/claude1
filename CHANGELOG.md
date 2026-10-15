# Changelog

## Business intelligence, payment risk, recovery, AI and adaptive pricing (programme — landing phase by phase)

The plan and what already exists is in [docs/AUDIT-AND-CHECKLIST.md](docs/AUDIT-AND-CHECKLIST.md). Each phase below is committed separately; a feature is listed only when its tests ran.

### Phase 2 — foundation
**Upgrading:** upload the files, then **Admin → System → Apply database updates** (or `php bin/migrate.php`). The five migrations only *add* tables and nullable / default-off columns; nothing is changed or removed. Rollback notes are at the top of each file.
* **Migrations** `005_finance` (`services.fulfil_cost_paise`, `order_costs`, `expenses`), `006_operations_risk` (`job_runs`, `business_alerts`, `payment_flags`, order recovery columns), `007_reports_ai_support` (`daily_reports`, `ai_usage_logs`, `support_conversations`, `support_messages`), `008_ads_intelligence` (`utm_content/term`, page / service views, engaged + returning flags, `ad_daily_spend`, `campaign_attribution`), `009_adaptive_pricing` (price versions, experiments, cohorts, assignments, decision and price audit logs, `orders.price_version_id / experiment_id / price_cohort`, per-service switches that default to **off**). All money is integer paise.
* **Roles and permissions** (`App\Support\Permissions`): `owner` (everything — every existing admin is one, so nothing changes), `manager`, `support`, `analyst`; sensitive actions name a permission and the **server** enforces it (`403 forbidden`). Assign with `php bin/admin.php role --username=… --role=…` (audited; the last active owner cannot be demoted).
* **Scheduled jobs** (`App\Services\Jobs`): a per-job advisory lock (no two processes run the same job), a bounded time budget, a run history (`job_runs`: start, finish, duration, small result, error *category* — never a stack trace, path or secret), stale `running` rows closed as `abandoned`, clean exit on failure. `php bin/cron.php` (one every-minute line, jobs run when due), `php bin/cron.php <job>`, `php bin/cron.php list`, `--dry-run`, exit code 1 on failure. The legacy HTTP cron and "Run background tasks now" keep working.
* **Business alerts** (`App\Services\Alerts`): one live alert per problem (a unique index, not a hope): re-detection counts up, severity only escalates, acknowledging keeps it live, resolving closes it, a check that sees the problem gone resolves it automatically, a recurrence opens a fresh one; sanitised text; optional e-mail through the host's `mail()` with a minimum severity and a cooldown (off by default).
* **State vocabulary**: the requested payment / order state names are provided as a mapping (`Status::specPayment()`, `Status::specOrder()`) over the existing, tested states. `REFUND_PENDING` is **not** supported (Alpha UPI has no refund API).
* Tests: PHP 212 passed (3,481 assertions, MariaDB); `admin-auth` 15/15 and `admin-integrations` 22/22 re-run.

## Payment page: the real QR, directly in the card (this release)

**Upgrading:** upload the files. No database change (migration `004` from the previous release is still the latest) and no asset rebuild (the compiled CSS/JS is included).

**What was wrong.** The previous release stopped drawing a QR of the gateway's hosted-checkout URL (a UPI app cannot pay an https address, and a camera opens it as a page that draws *another* QR) — but replaced it with an instruction card: *"open the secure payment page in a new tab and scan a second QR"*. That is not the experience wanted. The customer should scan **one** QR, on **our** page.

### The payment page
* **SOCIAL MEDIA BOOSTER → SECURE PAYMENT → PAY SECURELY → `₹5 — 50 Followers`** and one centred white card (rounded, subtle border, premium shadow): **SCAN AND PAY**, **the order's own QR** (up to 20 rem wide, high-contrast, quiet zone — scannable from another phone), the payee, **DOWNLOAD QR CODE** directly below it, and **TIME LEFT TO PAY 05:00** under that. Phones also get **OPEN UPI APP** (a phone cannot scan its own screen). The glassmorphism, logo, fonts and colours are unchanged.
* **Gone:** the instruction card ("You finish paying on our payment partner's secure page…", "Tap PAY ₹5 NOW. The secure payment page opens in a new tab", "scan its QR code…"), the **PAY ₹X NOW** button and the second-page workflow.
* **DOWNLOAD QR CODE** saves the very QR on screen (`GET /pay/{ref}/qr.png?download=1`, `SMB-XXXXXXXX-payment-qr.png` — the order id), not a screenshot of the page.
* The 05:00 countdown is still computed from the server's clock and sits under the QR; at 00:00 the QR, the download and the app button die and the page says *Payment session expired*.

### Where the QR comes from
* **What Alpha UPI documents** is a hosted checkout URL (`create-order` → `data.payment_url`) and nothing else — no QR image, no Base64, no QR payload, no UPI URI (the bundled SDK returns the same URL only). So there is nothing documented to "just display".
* **What the server now does:** it opens that documented `payment_url` itself (no credentials sent, the gateway's own host only, SSRF-guarded, 5 s, 600 KB), finds the `upi://pay?…` request the page's QR is made of, accepts it **only** if it is for exactly this order's amount in INR with a valid payee and known parameters, stores it with the payment, and draws the QR with its own PNG encoder. A QR of the https URL is never drawn. Every page view and every image request re-validates it against the payment's own amount.
* **When the page carries no usable request** (or the lookup is switched off, or the page is down), checkout sends the browser **straight to the gateway's own checkout page** in the same tab — the documented "redirect your customer to `payment_url`" — instead of an instruction card; our `/pay/{ref}` page then degrades to a slim **COMPLETE YOUR PAYMENT** card with the timer and one **CONTINUE TO PAYMENT** button.
* **Verification is unchanged and server-side only:** signed webhook or the server's own `check-status`; a view, a scan, a download or a click never marks anything paid; the exact amount, INR, a one-time UTR, duplicate webhooks, unknown orders, failed and expired sessions behave as before.

### Admin
* **Payment gateway → Check QR support:** asks first, creates one ₹1 test order at the gateway (never paid, not one of your orders) and says in words whether its checkout page carried a usable UPI request, and why not if it did not. Rate-limited, idempotent, audited (`gateway.qr_checked`).
* **Show the gateway's QR on my payment page** (default on): switch the lookup off to send every customer straight to the gateway's checkout.
* The local demo's fake gateway has a **fake bank** page at <http://127.0.0.1:4010/> to "pay" an order after its QR is shown.

### What this does **not** guarantee
* Reading a QR out of the gateway's checkout page is **not a documented API**. It works only while that page carries the UPI request in a readable form, and may stop working if the gateway changes it.
* **Not tested against the live gateway** (`alphaupi.site` was unreachable from the build machine) **and no real UPI payment was made.** The tests use a protocol-faithful mock whose page carries the request in several forms and, for the fallback, none. Use **Check QR support** on your server for the real answer.
* For a QR on your own page regardless of the gateway's page, you need a gateway (or an Alpha UPI plan) whose API returns the UPI request — ask Alpha UPI support, or add an adapter with `Gateways::register()` that sets `CreatePaymentResult::$upiIntent`.

### The service-link input (same release)
* **What was wrong:** the field auto-focuses when a card opens, and its focus state stacked a 4 px pink ring on a pink border inside a card that already had its own pink outline — a thick, glowing double border, worst on phones. A tap also left the hover colour behind.
* **Now:** one **1.5 px pink border** on a white field (the browser renders it as 3 physical pixels at 2× density, 4 at 3×, 1 on a 1× screen, where half pixels do not exist), **no shadow** at rest, and when focused **one soft 2 px ring** (the border deepens slightly; nothing is added to the wrapper, the form or the card; no layout shift). Valid / invalid use the same single border + 2 px ring in green / red; disabled is a quiet grey. Hover emphasis exists only on devices that can hover. The placeholder stays clear of the link icon and is cut with an ellipsis; the dark premium card's field is white too. The change is scoped to the storefront link field (`.input-wrap > .input`); admin inputs are untouched.
* **Verified on the rendered page** by a new suite, `tools/qa/site-input.mjs` (12 steps): a 360×800 Android phone (2×), a 390×844 iOS-sized phone (3×), a 768×1024 tablet (2×) and a 1440×900 desktop (1×), in a normal and in the dark premium card — border width and colour, thickness read from the screenshot pixels, exactly one shadow, no outline, wrapper / form / card unchanged, no layout shift, no overflow, a Tab-reachable focus indicator.

### Small phones
* The payment card overflowed a 360 px wide screen by 11 px (a grid item's natural width, found by the layout suite). The card may now shrink, and the paddings are a little tighter below 400 px so the QR stays large.

## Account links, INR only, and the payment QR (previous release)

**Upgrading:** upload the files, then **Admin → System → Apply database updates** (or `php bin/migrate.php`) to apply migration `004` — it only replaces the *old default* payment-page instructions text, never a text you wrote. Rebuilding assets is not needed (the compiled CSS/JS is included).

### Followers and Subscribers ask for the account link
* **Instagram Followers** → label **Instagram Profile Link**, placeholder `https://www.instagram.com/username/`. **YouTube Subscribers** → **YouTube Channel Link**, placeholder `https://www.youtube.com/@channelname`.
* No username or `@handle` is asked or accepted for these two. Accepted: `instagram.com/<name>` (with or without `https://`, `www.`, `m.`, a trailing slash, tracking parameters, `/_u/<name>`); `youtube.com/@handle`, `/channel/UC…`, `/c/<name>`, `/user/<name>`, a legacy `/<name>`. Refused: other domains and look-alikes (`instagram.com.evil.com`, credentials, odd ports, `ftp:`/`javascript:`), post / video links, the site root, malformed names.
* Validated live in the browser **and** again on the server (the server's answer is the one that counts; both run the same 219 fixture cases). The order keeps the complete validated link; the SMM panel receives it; the admin Orders list, drawer and **Manual delivery** section show it with *open* and *copy*; the customer's tracking page and "Your orders" drawer show it; the order history line carries it.
* Blue Tick, likes, views and watch time keep their current inputs.

### INR only
* The SMM provider form no longer has a currency (it defaulted to **USD** and offered INR / USD / EUR / GBP) or an exchange rate. A provider's charge is read as rupees; a charge a panel declares in another currency is **not converted** — the ₹ cost you enter on the service stays in force — and *Test connection* says when a panel bills in another currency. The dashboard's dollar-sign icon is now a rupee badge.
* A gateway that opens an order in another currency is refused; a success it reports in another currency is held for review and never fulfilled. Amounts are sent to the gateway as rupees with two decimals (`5.00`).
* Database defaults were already INR; nothing in the database is rewritten (the two legacy provider columns are set to `INR` / `1.0` only when you save that provider).

### Payment QR *(the card described here was replaced by the release above)*
* **Root cause:** the QR encoded the gateway's *hosted checkout URL*. A UPI app cannot pay an https URL and a camera opens it as a web page that draws another QR. The documented Alpha UPI API (and the bundled SDK) returns only that URL — no UPI request, QR string or image.
* The payment page draws a QR **only** for a real `upi://pay` request for exactly this order and amount (INR, valid payee, no repeated or unknown parameters), re-checked on every view and every `qr.png` request. *(That release then showed a hosted-checkout instruction card for Alpha UPI — removed in the release above, which reads the request from the gateway's own checkout page instead.)*
* Payments created before the fix are shown as hosted checkouts. The default payment instructions no longer promise a camera scan that opens a page.

## Tags & Badges
Ten predefined tags plus custom ones, assigned per service; the automatic **TRIAL PACK** badge on every ₹5 service. (Migration `003`.)

## Alpha UPI: the current API
`X-API-Key` + `X-API-Secret`, `/api/create-order`, `/api/check-status`, a webhook without `event_id`; the older single-key API stays selectable.
