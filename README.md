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
* Service cards exactly as specified (Followers, Likes, Views, Blue Tick, Subscribers, Watch Time; ₹5 trial packs, the red **TRIAL PACK** badge, the premium watch-time card).
* **BUY NOW** expands an inline form (slide-down, no navigation): **ENTER USERNAME OR LINK** with live **✓ Valid input / ⚠ Invalid username/link** validation, then **PROCEED TO PAYMENT**.
* Payment page: **SCAN AND PAY**, the gateway's QR, a real **05:00** countdown, **DOWNLOAD QR**, and an honest **"Payment session expired"** state (never a misleading success).
* Order tracking page and a "Your orders" drawer, both keyed by an unguessable token — customers never need an account.
* Fully responsive (360 px phones → 1920 px desktops), keyboard accessible (axe-core clean, WCAG 2.2 AA), SEO-ready (title, description, Open Graph, sitemap, robots, JSON-LD, web manifest).

### Admin panel (`/admin`)
* Hardened sign-in (rate limiting, lockout, hashed passwords, session expiry, show/hide password, forced first-login password change).
* **Dashboard:** 14 KPI cards (Today / Lifetime × revenue, profit, orders, visitors, successful payments, ads spend, SMM spend), charts for Today / 7 / 30 / 90 days / Lifetime / custom range, filters by platform / mode / payment, and the profit breakdown **REVENUE − SMM − ADS = NET PROFIT (+ margin)**.
* **Visitor analytics:** privacy-conscious, one visitor per day, *qualified* after 3 s of visible time, CPC (default **₹1.08**) charged once per qualified visitor-day.
* **Orders:** filters, search, detail drawer with timeline, every action (approve, complete, cancel, refund, retry, move to manual…), and **APPROVE ALL** (with confirmation: "Approve all eligible manual orders?").
* **Services:** AUTO / MANUAL, SMM service ID, provider cost, badge, icon upload, min/max, sort order, live profit preview.
* **Website settings** (name, logos, colours, SEO, support link), **payment gateway** configuration, **SMM provider** configuration with **TEST CONNECTION**, advertising/CPC settings, password change & session management, audit log, system health.

### Payments & orders
* Payment-gateway **abstraction** (`app/Payments`) with the official Alpha UPI SDK bundled unmodified; webhooks are signature-verified and **idempotent**.
* SMM API v2 client (server-side only; keys are AES-256-GCM encrypted at rest and never reach a browser).
* 13 order statuses with an enforced transition map; AUTO orders are claimed under a row lock so a provider order can never be sent twice; unclear provider answers are parked for a human instead of being retried blindly.
* Money is stored as **integer paise** and percentages as tenths — no floating point anywhere in accounting.

### Security (summary — details in [docs/SECURITY.md](docs/SECURITY.md))
Strict CSP (no inline scripts or styles), CSRF protection, same-origin checks, server-side price/status (the browser is never trusted), idempotency keys, rate limiting, upload validation + re-encoding, SSRF guard, audit log, secrets encrypted at rest, no secrets in logs or responses.

---

## Install in 5 minutes (cPanel)

1. Create a MySQL database and user in cPanel → **MySQL® Databases**; pick **PHP 8.1+** in **MultiPHP Manager**.
2. Upload the project (zip → File Manager → Extract) into `public_html`.
3. Open **`https://your-domain.com/install`**, follow the wizard (it checks the server, tests the database, writes `.env`, creates the tables and your admin account).
4. Add the one-minute cron job shown in **Admin → System**.
5. In **Admin → Payment gateway** enter your Alpha UPI API key and webhook secret, copy the webhook URL into the gateway dashboard, press **Test connection**.

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
php tests/run.php                 # 100 unit + integration tests (payments, webhooks, fulfilment, analytics, admin services …)
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
  Support/                Money (paise), Status (state machine), Targets (link validation), Qr, Tz, Installer, Migrator, NetGuard (SSRF) …
  Vendor/AlphaUpi/        the Alpha UPI PHP SDK, unmodified (MIT)
  Views/                  server-rendered templates (site, pay, order, admin shells, installer, errors, layouts)
  routes.php, routes_admin.php, bootstrap.php
bin/                    CLI: install.php, migrate.php, seed.php, admin.php (account recovery), cron.php
config/                 (reserved) · .env.example documents every setting
database/               schema.sql (full import), migrations/*.sql, seeds/catalog.php (the default service catalogue)
docs/                   deployment, API, database, security, profit & analytics, orders, QA report, third-party notices
public/                 the ONLY web-readable folder in the recommended layout: index.php, .htaccess, assets/ (compiled CSS/JS/fonts/icons), uploads/
resources/              source for the compiled assets (Tailwind CSS, JS modules, SVG icons)
storage/                logs, cache, install lock — never web-readable
tests/                  PHP test suite (no framework: tests/run.php)
tools/                  build scripts, mock gateway + mock SMM panel, QA suites (tools/qa)
```

## Configuration
Everything lives in `.env` (see [.env.example](.env.example)) plus the admin panel. Secrets (gateway key, webhook secret, SMM keys) are entered in the admin panel and stored encrypted with `APP_KEY`; **back up `APP_KEY`** — without it saved keys must be re-entered.

## Documentation
| | |
|---|---|
| [docs/DEPLOY-CPANEL.md](docs/DEPLOY-CPANEL.md) | production deployment on cPanel / shared hosting (layouts, installer, cron, HTTPS, upgrades, backups, troubleshooting) |
| [docs/API.md](docs/API.md) | every HTTP route, request/response shapes, auth, CSRF, idempotency, the webhook contract |
| [docs/DATABASE.md](docs/DATABASE.md) | tables, relationships, money/time conventions, migrations and seeding |
| [docs/ORDERS.md](docs/ORDERS.md) | order & payment lifecycles, AUTO vs MANUAL, failure handling table |
| [docs/PROFIT-AND-ANALYTICS.md](docs/PROFIT-AND-ANALYTICS.md) | profit maths, KPI definitions, visitor tracking and CPC |
| [docs/SECURITY.md](docs/SECURITY.md) | security model, the 13-point audit, operator checklist |
| [docs/QA-REPORT.md](docs/QA-REPORT.md) | final QA: what was tested, results, known limitations |
| [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) | licences of bundled third-party code |

## Honest notes
* **What was run:** PHP 8.3, MariaDB 10.11 and MySQL 8.0 (the whole QA battery on each), Apache + mod_php (three folder layouts) and Chromium. PHP 8.1/8.2/8.4, MySQL 5.7, LiteSpeed, NGINX, PHP-FPM, Firefox and Safari are supported by design but were not run — see [docs/QA-REPORT.md](docs/QA-REPORT.md) §5.
* The Alpha UPI and SMM integrations were verified against **faithful mock servers** that speak the real protocols (the QA suites use them); they were not run against live credentials — do a ₹5 test order in **test mode** first.
* The Instagram / YouTube / site logos supplied for this project arrived as images in the chat, not as files, so clean **SVG recreations** are bundled; upload your originals in **Admin → Website** (logos) and **Admin → Services** (platform logos) at any time — no code change needed.
* Alpha UPI exposes no refund API, so refunds are *recorded* in the panel (you refund through your gateway/bank); the books stay correct.
* New installs start with every service on **MANUAL**; switch a service to **AUTO** after adding its SMM service ID and provider cost.

## Licence
Application code: proprietary to the project owner unless you decide otherwise. Third-party components keep their own licences — see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
