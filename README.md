# SOCIAL MEDIA BOOSTER

A complete, production-ready **SMM panel** for selling Instagram and YouTube growth services in India: a premium glassmorphism storefront,
UPI payments through the **Alpha UPI** gateway, automatic delivery through any standard **SMM provider API**, a manual-order queue with
one-click **Approve All**, and an admin panel whose analytics dashboard shows revenue, SMM cost, ad spend and **net profit** to the paisa.

* **Stack:** PHP 8.1+ · MySQL 5.7+ / MariaDB 10.3+ · HTML · Tailwind CSS · vanilla JavaScript (ES modules) · no framework, no Composer, no Node on the server.
* **Hosting:** built for **cPanel / shared hosting** (Apache or LiteSpeed). Upload, open `/install`, done. The compiled CSS/JS is committed, so no build step is needed in production.
* **Status:** feature-complete and QA'd — see [docs/QA-REPORT.md](docs/QA-REPORT.md) for what was tested, how, and the results.

---

## What you get

### Customer website
* Header with a circular, glowing logo, an **admin-editable site name** and a black filled **Shop** icon (opens "Your orders").
* Two large platform buttons — **Instagram** and **YouTube** — with admin-uploadable logos, spring/glow animations and a platform-specific service list.
* Service cards exactly as specified (Followers, Likes, Views, Blue Tick, Subscribers, Watch Time; ₹5 trial packs; the premium watch-time card). Each card can carry one coloured **badge** at its top edge — one of the 10 predefined tags (**TRIAL PACK, BEST SELLER, POPULAR, BEST VALUE, PREMIUM, HOT DEAL, NON DROP, INSTANT, LIMITED OFFER, VIP**) or a custom one — chosen per service in the admin. **TRIAL PACK** is automatic: it is shown on **every service priced at exactly ₹5** (both platforms, decided from the database price in integer paise) and takes priority over the assigned tag; change the price and the assigned tag comes back.
* **BUY NOW** expands an inline form (slide-down, no navigation). **Instagram Followers** ask for the **Instagram Profile Link** (`https://www.instagram.com/username/`) and **YouTube Subscribers** for the **YouTube Channel Link** (`https://www.youtube.com/@channelname`, `/channel/UC…`, `/c/…`, `/user/…`) — no username is asked, a bare `@name` is refused, and the link is validated live in the browser and again on the server (right platform, http/https only, no look-alike domains), then saved with the order and sent to the SMM panel. The other services keep **ENTER USERNAME OR LINK** (Blue Tick) or ask for the post / video link. Live **✓ Valid link / ⚠ Invalid link** messages, then **PROCEED TO PAYMENT**.
* Payment page with a real server-side **05:00** countdown and an honest **"Payment session expired"** state (never a misleading success). **PAY SECURELY → SCAN AND PAY**: the order's own QR drawn directly in the card (a real `upi://pay` request for exactly this order and amount — a UPI app opens on the payment screen), a **DOWNLOAD QR CODE** button right below it (the same PNG, named after the order) and the timer under that; phones also get **OPEN UPI APP**. The payment is confirmed only by the server (signed webhook / gateway status check). If the gateway gives no QR, the customer goes straight to the gateway's own checkout page instead — never to an instruction card. See [docs/ORDERS.md §3a](docs/ORDERS.md).
* **INR only.** Every price, payment amount and provider cost is in ₹; there is no currency picker or exchange-rate setting anywhere.
* Order tracking page and a "Your orders" drawer, both keyed by an unguessable token — customers never need an account.
* Fully responsive (360 px phones → 1920 px desktops), keyboard accessible (axe-core clean, WCAG 2.2 AA), SEO-ready (title, description, Open Graph, sitemap, robots, JSON-LD, web manifest).

### Admin panel (`/admin`)
* Hardened sign-in (rate limiting, lockout, hashed passwords, session expiry, show/hide password, forced first-login password change).
* **Dashboard:** 14 KPI cards (Today / Lifetime × revenue, profit, orders, visitors, successful payments, ads spend, SMM spend), charts for Today / 7 / 30 / 90 days / Lifetime / custom range, filters by platform / mode / payment, and the profit breakdown **REVENUE − SMM − ADS = NET PROFIT (+ margin)**.
* **Visitor analytics:** privacy-conscious, one visitor per day, *qualified* after 3 s of visible time, CPC (default **₹1.08**) charged once per qualified visitor-day.
* **Orders:** filters, search, detail drawer with timeline, every action (approve, complete, cancel, refund, retry, move to manual…), and **APPROVE ALL** (with confirmation: "Approve all eligible manual orders?").
* **Services:** AUTO / MANUAL, SMM service ID, provider cost, tag dropdown ("No Tag" or any enabled tag) with a live preview on the real service card, icon upload, min/max, sort order, live profit preview.
* **Services → Tags & Badges:** create custom tags, rename them, change the background (colour picker **and** HEX box) and text colour, enable / disable (a disabled tag is hidden on the store but keeps its assignments), delete custom tags (services that used one simply lose the badge), preview every badge before saving. The 10 predefined tags can be edited and disabled, not deleted.
* **Business alerts** (Admin → Overview): the *Business Watchdog* runs twelve health checks every 15 minutes on the shop's own records — failing payments, bad or repeated payment notifications, SMM provider errors, stuck orders, a rise in the provider's cost, services with no cost set, a drop in sales or conversion, failing scheduled jobs, application errors, money that does not add up, and (off by default) the provider's balance — and raises **one alert per problem** (acknowledge, resolve, resolved by itself only after several clean runs). Thresholds are editable within hard limits; a check that cannot judge says *not enough data*, never *OK*. Optional e-mail, off by default. These are fixed rules and comparisons with recent history — **not an AI**. See [docs/WATCHDOG-AND-REPORTS.md](docs/WATCHDOG-AND-REPORTS.md).
* **Daily reports** (Admin → Overview): one report for every finished business day (Asia/Kolkata), written by the daily job — revenue, orders, costs, profit, margin, visitors, a comparison with the day before, the most profitable / loss-making / unknown-cost services, API and payment failures and the alerts open at the time — each figure labelled *measured*, *estimated*, *incomplete* or *unavailable*, with plain-language highlights written by fixed rules.
* **System → Scheduled jobs:** every job's last run, last success and failures, with *Run now* and *Dry run* ([docs/OPERATIONS.md](docs/OPERATIONS.md)).
* **Website settings** (name, logos, colours, SEO, support link), **payment gateway** configuration, **SMM provider** configuration with **TEST CONNECTION**, advertising/CPC settings, password change & session management, audit log, system health.

### Payments & orders
* Payment-gateway **abstraction** (`app/Payments`). The Alpha UPI adapter speaks the **current Alpha UPI API** (`X-API-Key` + `X-API-Secret` headers, `/api/create-order`, `/api/check-status` — the one documented for alphaupi.site) and, selectable in the admin, the older single-key API of the bundled official SDK (unmodified). Webhooks are signature-verified and **idempotent**.
* SMM API v2 client (server-side only; keys are AES-256-GCM encrypted at rest and never reach a browser).
* 13 order statuses with an enforced transition map; AUTO orders are claimed under a row lock so a provider order can never be sent twice; unclear provider answers are parked for a human instead of being retried blindly.
* Money is stored as **integer paise** and percentages as tenths — no floating point anywhere in accounting.

### Security (summary — details in [docs/SECURITY.md](docs/SECURITY.md))
Strict CSP (no inline scripts or styles), CSRF protection, same-origin checks, server-side price/status (the browser is never trusted), idempotency keys, rate limiting, upload validation + re-encoding, SSRF guard, audit log, secrets encrypted at rest, no secrets in logs or responses.

---

## Try it on your own computer (one command)

```bash
php tools/local-demo.php
```

Installs the whole site with ~75 days of demo data, starts a **fake payment gateway** (it speaks the current Alpha UPI API: `X-API-Key` + `X-API-Secret`) and a **fake SMM panel** (so you can place and pay an order end to end) and prints your admin login. Needs PHP 8.1+, a running MySQL/MariaDB (XAMPP, MAMP, Homebrew …) and, for the fake gateway, Node.js 18+. Details and troubleshooting: **[docs/RUN-LOCALLY.md](docs/RUN-LOCALLY.md)**.

---

## Install in 5 minutes (cPanel)

1. Create a MySQL database and user in cPanel → **MySQL® Databases**; pick **PHP 8.1+** in **MultiPHP Manager**.
2. Upload the project (zip → File Manager → Extract) into `public_html`.
3. Open **`https://your-domain.com/install`**, follow the wizard (it checks the server, tests the database, writes `.env`, creates the tables and your admin account).
4. Add the one-minute cron job shown in **Admin → System**.
5. In **Admin → Payment gateway** enter the base URL `https://alphaupi.site`, your Alpha UPI **API key**, **API secret** and **webhook secret**, copy the webhook URL into the gateway's Webhooks portal, press **Test connection**, then place a ₹5 test order (Alpha UPI has no sandbox).

The complete walkthrough — folder layouts, permissions, HTTPS, cron, upgrades, backups, troubleshooting — is in **[docs/DEPLOY-CPANEL.md](docs/DEPLOY-CPANEL.md)**.

### Command-line install (SSH / cPanel Terminal)
```bash
php bin/install.php --url=https://example.com --db-name=cpuser_smm --db-user=cpuser_smm --db-pass='…' \
    --admin-username=owner --admin-email=you@example.com
```
Locked out? `php bin/admin.php reset-password --username=owner` prints a new password once.

---

## Local development

```bash
# 1. database
mysql -uroot -e "CREATE DATABASE smb_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER 'smb'@'127.0.0.1' IDENTIFIED BY 'change-me'; GRANT ALL ON smb_dev.* TO 'smb'@'127.0.0.1';"
cp .env.example .env            # APP_ENV=local, APP_DEBUG=true, DB_* …
php bin/migrate.php && php bin/seed.php --admin-username=owner --admin-email=you@example.test --admin-password='a-long-Passw0rd!'

# 2. run (PHP's built-in server; tools/router.php mimics the .htaccess rewrite rules)
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8080 -t public tools/router.php

# 3. mock payment gateway + mock SMM panel (local testing only: with ALLOW_PRIVATE_API_HOSTS=false, the default, production refuses private/local API addresses)
node tools/mock-alpha-upi/server.js &   node tools/mock-smm/server.js &
php tools/dev-setup.php demo    # points the gateway/SMM at the mocks and generates ~60 days of demo data
```

Rebuild the compiled assets after editing anything in `resources/`:

```bash
npm install
npm run build        # icons sprite + Tailwind CSS + JS bundles → public/assets/
```

### Tests
```bash
php tests/run.php                 # 185 unit + integration tests (payments, webhooks, both Alpha UPI APIs, the UPI payment-request / QR rules, link validation, INR only, fulfilment, analytics, admin services, tags & badges …)
bash tools/qa/run-all.sh          # every browser/HTTP QA suite (Playwright, axe-core, real Apache) — see docs/QA-REPORT.md
```

---

## Folder map

```
app/                    PHP application (PSR-4 namespace App\, autoloaded without Composer)
  Core/                   Kernel, Router, Request/Response, Db (PDO), Env, Crypto (AES-256-GCM), Csrf, RateLimiter, Idempotency, error pages
  Controllers/            thin HTTP controllers (public site, checkout, pay, order, webhook, cron, installer) and Admin/*
  Services/               business logic: Orders, Settlement, Fulfilment, PaymentService, Catalog, Tracking, Ads, Analytics, Dashboard, Admin*
  Payments/               gateway abstraction + the Alpha UPI adapter           Smm/  SMM API v2 client
  Support/                Money (paise), Status (state machine), Targets (link validation), Upi (UPI payment request check), Qr, Tz, Installer, Migrator, NetGuard (SSRF) …
  Vendor/AlphaUpi/        the Alpha UPI PHP SDK, unmodified (MIT)
  Views/                  server-rendered templates (site, pay, order, admin shells, installer, errors, layouts)
  routes.php, routes_admin.php, bootstrap.php
bin/                    CLI: install.php, migrate.php, seed.php, admin.php (account recovery), cron.php
config/                 (reserved) · .env.example documents every setting
database/               schema.sql (full import), migrations/*.sql, seeds/catalog.php (the default service catalogue)
docs/                   deployment, API, database, security, profit & analytics, orders, QA report, third-party notices
public/                 the ONLY web-readable folder in the recommended layout: index.php, .htaccess, assets/ (compiled CSS/JS/fonts/icons), uploads/
resources/              source for the compiled assets (Tailwind CSS, JS modules, SVG icons) and the supplied brand files (brand/)
storage/                logs, cache, install lock — never web-readable
tests/                  PHP test suite (no framework: tests/run.php)
tools/                  build scripts, mock gateway + mock SMM panel, QA suites (tools/qa)
```

## Configuration
Everything lives in `.env` (see [.env.example](.env.example)) plus the admin panel. Secrets (gateway key, webhook secret, SMM keys) are entered in the admin panel and stored encrypted with `APP_KEY`; **back up `APP_KEY`** — without it saved keys must be re-entered.

## Documentation
| | |
|---|---|
| [docs/RUN-LOCALLY.md](docs/RUN-LOCALLY.md) | run everything on your own computer with demo data and a fake gateway (one command) |
| [docs/DEPLOY-CPANEL.md](docs/DEPLOY-CPANEL.md) | production deployment on cPanel / shared hosting (layouts, installer, cron, HTTPS, upgrades, backups, troubleshooting) |
| [docs/API.md](docs/API.md) | every HTTP route, request/response shapes, auth, CSRF, idempotency, the webhook contract |
| [docs/DATABASE.md](docs/DATABASE.md) | tables, relationships, money/time conventions, migrations and seeding |
| [docs/ORDERS.md](docs/ORDERS.md) | order & payment lifecycles, AUTO vs MANUAL, failure handling table |
| [docs/PAYMENT-CHECKS.md](docs/PAYMENT-CHECKS.md) | how a payment becomes paid, what is flagged and why, the webhook cross-check, thresholds, roles, what was tested |
| [docs/ORDER-RECOVERY.md](docs/ORDER-RECOVERY.md) | which paid orders are stuck and why, what is retried by itself and what never is, the safe actions, the retry policy, the cron job, what was tested |
| [docs/PROFIT-INTELLIGENCE.md](docs/PROFIT-INTELLIGENCE.md) | what an order really cost, the profit statement, *actual / estimated / incomplete* labels, the fee model, expenses and chargebacks, reconciliation, who may see profit, what was tested |
| [docs/ADS-INTELLIGENCE.md](docs/ADS-INTELLIGENCE.md) | campaign tracking, how a verified order is credited, actual vs estimated spend, the units of every figure, confidence labels, what was tested |
| [docs/WATCHDOG-AND-REPORTS.md](docs/WATCHDOG-AND-REPORTS.md) | the twelve watchdog checks and their thresholds, how alerts are raised, deduplicated and resolved, the optional e-mail, the daily report and how to read its labels, what was tested |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | the scheduled jobs, the cPanel cron lines, what each job guarantees, the time zone, retention, what to check when a job is overdue |
| [docs/AUDIT-AND-CHECKLIST.md](docs/AUDIT-AND-CHECKLIST.md) | the business-intelligence / pricing programme: what existed, the gaps, gateway capabilities, the phase checklist |
| [docs/PROFIT-AND-ANALYTICS.md](docs/PROFIT-AND-ANALYTICS.md) | profit maths, KPI definitions, visitor tracking and CPC |
| [docs/SECURITY.md](docs/SECURITY.md) | security model, the 13-point audit, operator checklist |
| [docs/QA-REPORT.md](docs/QA-REPORT.md) | final QA: what was tested, results, known limitations |
| [CHANGELOG.md](CHANGELOG.md) | what changed in each release, and what to do when upgrading |
| [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) | licences of bundled third-party code |

## Honest notes
* **What was run:** PHP 8.3, MariaDB 10.11 and MySQL 8.0 (the whole QA battery on each), Apache + mod_php (three folder layouts) and Chromium. PHP 8.1/8.2/8.4, MySQL 5.7, LiteSpeed, NGINX, PHP-FPM, Firefox and Safari are supported by design but were not run — see [docs/QA-REPORT.md](docs/QA-REPORT.md) §5.
* The Alpha UPI (current and older API) and SMM integrations were verified against **faithful mock servers** that speak the documented protocols (the QA suites use them); they were not run against live credentials — do a ₹5 test order in **test mode** first.
* Your logo and your Instagram / YouTube icons are used exactly as supplied (kept in `resources/brand/`); the header logo, favicon, app icons, sharing image and platform buttons are cropped / resized from them (`python3 tools/build-brand-images.py`). Replace them any time in **Admin → Website** (logo, favicon) and **Admin → Services** (platform logos) — no code change needed.
* Alpha UPI exposes no refund API, so refunds are *recorded* in the panel (you refund through your gateway/bank); the books stay correct.
* **Where the payment QR comes from.** Alpha UPI's documented API (and the bundled SDK) returns one thing: a hosted checkout URL — no QR image, Base64, payload or UPI URI. A QR of that URL is exactly the "QR that opens a web page with another QR", so it is never drawn. Instead the server opens the gateway's own `payment_url` and reads the `upi://pay?…` request that page's QR is made of, accepts it only if it is for exactly this order and amount (INR), and draws the QR itself. **That reading is not a documented gateway API and was not tested against the live gateway** (it could not be reached from the build machine; no real payment was made): it works while the gateway's page carries the request in a readable form. **Admin → Payment gateway → Check QR support** creates a ₹1 test order (never paid) and tells you whether the real page carries one; if it does not, customers are sent to the gateway's checkout page, and a QR on your own page needs a gateway or plan whose API returns the UPI request (ask Alpha UPI support, or add an adapter with `Gateways::register`).
* **Provider charges are read as ₹.** An SMM panel that bills in another currency is not converted (the shop has no exchange rate): its charge is ignored and the ₹ cost you enter on the service stays in force for profit figures; **TEST CONNECTION** tells you when a panel bills in another currency.
* New installs start with every service on **MANUAL**; switch a service to **AUTO** after adding its SMM service ID and provider cost.

## Licence
Application code: proprietary to the project owner unless you decide otherwise. Third-party components keep their own licences — see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
