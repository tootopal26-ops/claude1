# Final QA report

> **Result: every automated suite passes** (table in §2). This report says what was tested, how, what the QA found and fixed, and — just as important — what was **not** tested.
> Re-run everything yourself: `bash tools/qa/run-all.sh` (≈ 20 min; add `--long` for the real-time 5:00 countdown test — the complete run took 24 min). Last complete run on MariaDB 10.11: **18 of 18 suites passed** (table below). The same battery (everything except the 5-minute real-time countdown test) was then run against a real **MySQL 8.0.46** server: **17 of 17 passed** — the one first-run failure there was a timing-sensitive animation check that sampled a single instant; it now samples every frame and passes on both engines.

## 1. Method

QA was done by **attacking and driving the running application** rather than reading the code:

* **Real browser** — Chromium via Playwright, desktop and phone/tablet emulation (touch, device pixel ratio, mobile user-agent), with console errors, CSP violations, failed requests and HTTP ≥ 400 collected on every page load.
* **Real servers** — the PHP application on MariaDB; **real Apache + mod_php** for deployment checks; mock servers that speak the real Alpha UPI and SMM v2 protocols (the real SDK/adapter code runs against them), with switches to inject failures (offline, timeout, garbled replies, bad key, quota, duplicate webhooks…).
* **Independent oracles** — ZXing and jsQR decode every QR we generate; axe-core audits accessibility; the dashboard is reconciled to raw SQL rows; the JS and PHP link validators are checked against the same 124-case fixture.
* **Adversarial** — a 13-point security audit written as an attacker (see [SECURITY.md](SECURITY.md)), including concurrency (12–20 simultaneous requests), tampered/replayed webhooks, path tricks and hostile inputs.

Environment: PHP 8.3.6 · **MariaDB 10.11** and **MySQL 8.0.46** (the whole battery was run on both) · Apache 2.4.58 + mod_php · Chromium (Playwright 1.64) · Linux. Local dev data: ≈ 480 orders, 75 days of demo history, ≈ 10,000 visitors.

## 2. Results

| Suite (`tools/qa/…`) | What it covers | Result |
|---|---|---|
| `php tests/run.php` | 107 unit + integration tests, 1,625 assertions: checkout, webhooks (signature, dedupe, amount mismatch, concurrency), settlement races (8 parallel processes), expiry, late payments, AUTO/MANUAL fulfilment and every provider failure (incl. a crashed or killed submission), Approve All concurrency, refunds, profit maths (PHP ≡ SQL, loss-making orders), analytics, admin validation, uploads, installer, settings, router, link validation, QR round-trip | **107 / 107** on MariaDB 10.11 **and** on MySQL 8.0 |
| `targets-parity.mjs` | browser and server link validators agree on 124 cases | **pass** |
| `admin-auth.mjs` | sign-in, lockout, throttling, sessions, CSRF, forced password change, every admin API unauthenticated | **15 / 15** |
| `admin-dashboard.mjs` | 14 KPIs, ranges, filters, charts, profit breakdown, visitor analytics | **15 / 15** |
| `admin-orders.mjs` | filters, search, drawer, every action, **Approve All** (confirm, double click, idempotency), pagination, failures | **21 / 21** |
| `admin-catalog.mjs` | service create/edit/price/delete, validation, profit preview, icon + logo upload, website settings, double-click Save | **25 / 25** |
| `admin-integrations.mjs` | gateway & SMM config (secrets write-only), **test connection**, advertising/CPC, password & sessions, audit log, system page, database updates | **20 / 20** |
| `admin-mobile.mjs` | admin on phones/tablets: navigation drawer, dialogs fit, touch charts, tap targets, no overflow and **no covered controls** on all 11 pages at 4 widths × 3 scroll positions | **10 / 10** |
| `install-e2e.mjs` | web installer end to end on a fresh database + CLI installer + recovery tools | **14 / 14** |
| `site-flows.mjs` | the whole customer journey at 1440×900 and on a phone: SEO/head, header, platform tabs, exact catalogue, BUY NOW, validation, checkout, QR + download (decoded), timer, webhook success, MANUAL, failed/expired/late payments, duplicate webhooks, Shop drawer, tracking page | **35 / 35** |
| `site-layout.mjs` | **8 viewports** (1366×768, 1440×900, 1920×1080, 360×800, 390×844, 412×915, 768×1024, 820×1180) × 6 screens: overflow, clipping, off-screen, tap targets, fonts, line-height, overlap, **covered controls (z-index)**, sizes of header/logo/buttons/cards/inputs/QR; 88 screenshots reviewed | **48 / 48** |
| `site-failures.mjs` | double-click, lost response, 12 parallel checkouts, gateway offline/bad key/quota/no provider/**timeout**, browser offline, connection drop on the payment page, payment pending, SMM unreachable/dropped/invalid key/invalid service/no balance/garbled/hang, admin retry, **database down** and **database lock timeout** | **24 / 24** |
| `site-tracking.mjs` | visitor counted once per day, 3 s *visible* qualification, one CPC per visitor-day, CPC snapshot, bots, forged/replayed qualify, funnel flags, dashboard ≡ raw rows | **7 / 7** |
| `security-audit.mjs` | the **13-point final security audit** + hardening (headers, cookies, CSRF/origin, host-header, open redirect, XSS, methods, limits) against a production-like server | **16 / 16** |
| `a11y.mjs` | axe-core WCAG 2.2 A/AA + best practice on every customer and admin screen at desktop and phone (**0 violations**), keyboard/focus trap/skip link/reduced motion/200 % zoom/320 px reflow | **19 / 19** |
| `perf.mjs` | page weight, requests, gzip, caching, Web Vitals on Slow-4G + 4× CPU, DB queries per request, API chatter | **8 / 8** |
| `apache-check.mjs` | real Apache + mod_php in layouts A, B and a **sub-folder**: blocked paths, inert uploads and assets, stray PHP refused while other sites in sub-folders keep working, headers, gzip, caching, a full order → webhook → SMM → cron round trip, **webhook answered at once while the provider hangs** | **21 / 21** |
| `pay-real-expiry.mjs` (`--long`) | the **real** 5:00 countdown, warn at 1:00, critical at 0:30, expiry at 00:00 within 2 s, QR removed, success never shown, server agrees | **4 / 4** |

## 3. The brief's mandatory checks

### §55–56 Viewports & measurements
Tested at **1366×768, 1440×900, 1920×1080** (desktop), **360×800, 390×844, 412×915** (phones) and **768×1024, 820×1180** (tablets). For every screen the suite asserts: no horizontal overflow; nothing off-screen; no clipped text (measured from the text itself); tap targets ≥ 24 px everywhere and ≥ 44 px for BUY NOW / PROCEED / close buttons; fonts ≥ 11 px, body copy ≥ 13 px, inputs ≥ 16 px (no iOS zoom); no overlapping controls; no controls covered by another element (z-index); header 52–96 px with a circular logo ≥ 38 px and a Shop button ≥ 40 px; platform buttons large and equal; 1 column on phones / 2 on ≥ 640 px; the premium card spans the full row; the drawer and dialogs stay inside the viewport; QR 220–360 px and the timer in the first screen. Contrast of the key text/background pairs was computed (all ≥ 4.5:1; the smallest, the red TRIAL PACK badge, is 4.83:1).

### §57 Interaction QA → where each item is exercised
BUY NOW, platform selector, service selector, username/URL inputs, Proceed, QR download, timer, payment success/failure, order creation, AUTO/MANUAL orders → `site-flows`, `site-failures`, `security-audit`, PHP suite · Approve / Approve All → `admin-orders`, PHP concurrency tests · service & price editing, logo upload → `admin-catalog` · API test connection / payment-gateway test → `admin-integrations` · password change, logout → `admin-auth`, `security-audit` (cookie replay) · mobile and desktop navigation → `admin-mobile`, `admin-dashboard`.

### §58 API failure handling — each shows a useful state
| Situation | What the customer sees | What the admin gets |
|---|---|---|
| SMM API offline (connection refused) | "Payment received — starting your order" | automatic retries 1/5/20/60 min, reason + next attempt; succeeds exactly once after recovery |
| Connection dropped mid-request / garbled reply / timeout | "Needs attention — our team has been notified" | order parked as *ambiguous* (never blind-retried: no double-buy), instructions to verify at the provider |
| Invalid API key / invalid service ID / insufficient balance | same calm message | the provider's exact reason, **Retry** / **Move to manual** |
| Payment gateway offline / bad key / quota / no provider | "Payment is temporarily unavailable. Please try again in a few minutes." (button re-enabled, no stuck order) | gateway warning on the dashboard |
| Payment gateway timeout (20 s) | same, after 20 s, with progress shown meanwhile | — |
| Webhook duplicate | nothing changes (20 concurrent deliveries → one settlement) | event log shows each delivery |
| Payment pending | "Waiting for your payment…" indefinitely, never success | — |
| Payment failed | "Payment failed" + TRY AGAIN | — |
| Database unreachable | branded "temporarily unavailable" page / JSON 503, no details | `/health` 503, log entry |
| Database lock timeout | friendly 503 within 20 s (not a hang), recovers when the lock clears | — |
| Network error (browser) | "Network error. Please check your connection…" / "Connection problem — retrying…", recovers by itself | — |

### §59 Double-click protection
| Action | Mechanism | Test |
|---|---|---|
| Order creation | button disabled + idempotency key kept across lost responses + advisory lock + same-session reuse | `site-failures` (double click → 1 request/1 order; lost response; 12 parallel with one key; 12 parallel with different keys) |
| Payment | one session per order; settlement under row lock; idempotent webhook | `security-audit` Q8, PHP races |
| Approve / Approve All | one transaction, eligibility re-checked, idempotency key required | `admin-orders`, PHP concurrency |
| Settings save / Service save | button locks while saving; idempotency key replays the stored answer | `admin-catalog` |

### §60 Performance (Apache, gzip; phone = Slow-4G, 4× CPU slow-down)
| Metric | Desktop | Phone |
|---|---|---|
| Requests / bytes on the wire, cold | **17 / 96 KB** (HTML 5.4, CSS 19, JS 12, fonts 49) | same |
| First/Largest contentful paint | **≈ 0.17 s / ≈ 0.2–1.0 s** | **0.94 s / 0.94 s** |
| Cumulative layout shift · blocking time | **0.000 · 0 ms** | **0.000 · 0 ms** |
| Repeat visit | **16 requests, 6 KB** (static files from cache) | — |
| Payment page (phone, Slow-4G) | LCP 0.87 s, 91 KB, 5.8 KB JS | |
| Server time / DB statements | storefront **3.0 ms / 3 queries**; payment page 4.3 ms / 8; poll 2.6 ms / 6; beacon 4.7 ms / 10 | |

Server-side rendering (every price is in the first HTML), lazy-loaded icons with explicit sizes (no shift), self-hosted preloaded font, one stylesheet, ≈ 14 KB of JavaScript, immutable asset caching, one tracking beacon per visit, payment polling every ≈ 3 s.

### §61 Final security audit
All 13 questions answered **No** — see the table in [SECURITY.md](SECURITY.md#3-the-13-point-final-audit).

## 4. What the QA found and fixed

**Found during this final phase** (each now covered by a regression test):

| # | Finding | Fix |
|---|---|---|
| 1 | **Sub-folder installs:** payment polling requested `/booster/booster/api/…` → 404, the page never flipped to "paid" | API helper no longer re-prefixes server-built URLs |
| 2 | **Duplicate orders** possible with 12 simultaneous checkouts from one visitor | per-visitor/service/link advisory lock around check-and-create |
| 3 | **Lost response → retry created a second order** for visitors without cookies | the browser keeps the idempotency key when the outcome is unknown; the server replays the original order |
| 4 | An AUTO service with no SMM id / inactive provider was **sellable** (customer pays, order fails) | such services are hidden from the store and refused at checkout; admin banner explains |
| 5 | A second payment (different bank reference) on a paid order was silent | noted on the order for refund |
| 6 | Database lock waits were unbounded (MariaDB default 24 h) | bounded lock waits, statement cap, one retry only → friendly 503 in ≤ 20 s |
| 7 | `Origin: null` was accepted on unsafe requests | refused |
| 8 | Expired/paid payment pages still rendered the QR `<img src>` and pay links (hidden) | not rendered unless the session is live |
| 9 | Control / zero-width characters in a link were stored | stripped before storing |
| 10 | The Shop icon on the order-tracking page did nothing | links to the drawer on the home page |
| 11 | Countdown steps were 0.8–1.25 s (fixed 250 ms interval) | ticks land on whole seconds |
| 12 | `.htaccess` duplicated `X-Content-Type-Options` and `max-age` on PHP responses; favicon uncached | rules scoped to static files |
| 13 | Dev router: path traversal and a NUL-byte crash (development only) | `realpath` confinement |
| 14 | Long link wrapped mid-word ("htt/ps://…") on the order page; short cards floated mid-screen on phones; Shop drawer button small | wrapping, top alignment, full-width button |
| 15 | **Accessibility:** heading order, no `main` landmark on error pages, duplicate landmark names, invalid ARIA on radio groups/combobox, unfocusable scroll regions, order rows nested interactive controls, boolean ARIA rendered empty, 36 px close buttons | all fixed (axe: 0 violations; close buttons 44 px) |
| 16 | Admin had no way to apply a database update without SSH | **Admin → System → Apply database updates** + banner |
| 17 | **MySQL 8 only:** the dashboard's profit SQL failed with "BIGINT UNSIGNED value is out of range" as soon as one order lost money (a refunded AUTO order still carries its provider cost; a pack sold below cost). MariaDB types `CASE` differently and hid it. Found by running the PHP suite on a real **MySQL 8.0** server | every connection sets `NO_UNSIGNED_SUBTRACTION` (the columns still refuse negative values); regression test on whichever engine runs |
| 18 | On plain mod_php / CGI (PHP-FPM and LiteSpeed are fine) the **payment webhook was held open while the SMM provider was called**: a hanging provider kept the gateway's request open for 30 s → gateway time-outs and retries | the answer carries an exact `Content-Length` + `Connection: close`; measured **21 ms** (dev server) and **≈ 125 ms** (real Apache) against a provider that hangs for 30 s; asserted in `site-failures` (all 7 provider cases) and `apache-check` |
| 19 | A paid AUTO order could stay in **PROCESSING for ever** if the PHP process died (host limit, restart) or hit an unexpected error after claiming it | the provider call is wrapped (unexpected error → FAILED/ambiguous for a human); a cron **watchdog** hands orders stuck > 10 min to an admin (never auto-resubmitted); if the provider accepted but the database refused the note, the provider's order id is logged for reconciliation |
| 20 | SMM client: a second DNS look-up could throw out of the request and was not re-checked against the private-address rule (DNS-rebinding window) | handled (→ retryable "unreachable") and re-validated |
| 21 | `public/.htaccess` refused **every** `.php` file below the web root, which would break other PHP apps in sub-folders of the same document root (typical for cPanel add-on domains); a static site's `index.html` was not served | the rule is anchored to the web-root folder itself, `assets/` is guarded separately, `DirectoryIndex` falls back to `index.html`; verified on real Apache (layout A and B) |
| 22 | An image upload could die with a PHP "memory exhausted" fatal on hosts with a small `memory_limit` | the pixel budget is checked against `memory_limit` first and a friendly message names a size that fits |
| 23 | The suggested cron line used a bare `php`, which in cron may be an older system PHP | Admin → System shows `/usr/local/bin/php` (cPanel's shim for the selected version) when it exists |
| 24 | PHP 8.4 / 8.5 deprecations (`E_STRICT` constant, `curl_close()`) | removed (a static scan for PHP > 8.1 syntax and functions found nothing else) |

**Found earlier** (admin, installer, payments; all with regression tests): Approve All button re-enabled after use; gateway "warning" level styled as an error; font preload URL mismatch; `.env` escape round-trip corruption; installer throttle that never accumulated; DB port "0" defaulting; CLI option parsing; generated passwords violating the policy; tables overflowing on phones; donut/KPI layout issues; a small tap target on the password toggle; and more — see the commit history.

## 5. Not tested / known limitations (honest list)

* **Live credentials.** Alpha UPI and the SMM provider were exercised against **protocol-faithful mocks**, not live accounts. Do a ₹5 order in test mode first. Provider-specific quirks (unusual error strings, rate limits, status names) may need small mapping tweaks in `app/Smm/` / `Status::fromProviderStatus()`.
* **Browsers:** all browser QA ran in **Chromium**. Firefox and Safari were not run (the code uses standard features — CSS `@property`, `color-mix`, `<dialog>`, `inert` — supported by current versions; older browsers degrade gracefully but were not verified). No real phones or screen readers were used; accessibility was audited with axe-core plus keyboard/focus tests.
* **Servers:** verified on Apache + mod_php (3 layouts) and PHP's built-in server. **LiteSpeed, PHP-FPM and NGINX were not run** (the PHP-FPM / LiteSpeed code path — `fastcgi_finish_request()` / `litespeed_finish_request()` — is the standard PHP function, but unexercised here; the `.htaccess` is plain Apache 2.2/2.4 syntax).
* **PHP versions:** everything ran on **PHP 8.3**. The code targets 8.1+, and a static scan for syntax and functions introduced after 8.1 (readonly classes, typed constants, DNF types, `json_validate`, `array_find`…) found none — but PHP 8.1, 8.2 and 8.4 were not run.
* **Databases:** the whole battery — the 107 PHP tests, the installer, the admin and storefront browser suites, the failure injections (including a database lock timeout) and the security audit — passes on **both MariaDB 10.11 and MySQL 8.0.46** (running it on MySQL 8 is how finding 17 was caught). **MySQL 5.7 and MariaDB 10.3–10.6** were not run; no CTEs, window functions, JSON columns or `CONVERT_TZ()` are used.
* **Scale:** no load test beyond concurrency tests (12–20 parallel requests). The design (indexed queries, 3 queries per storefront hit, MySQL-backed limits) is built for small-to-medium shops on shared hosting, not for thousands of requests per second.
* **Alpha UPI has no refund API**, so refunds are recorded, not executed.
* **Email / SMS notifications** are not part of the brief and are not implemented; admins see everything in the panel.
* The Instagram/YouTube/site logos supplied for the project arrived as images in the chat, not files, so clean SVG recreations ship; upload your originals in the admin panel.
