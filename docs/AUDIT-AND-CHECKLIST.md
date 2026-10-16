# Phase 1 — Audit of the existing system and the implementation checklist

This document is the result of reading the repository before the "business intelligence, security, automation and AI" work
(Profit Intelligence, Payment & Fraud Checks, Order Recovery, Business Watchdog, Daily Report, Support Assistant, Ads Intelligence,
and the Adaptive Pricing engine with its Simulator, Profit Guard and Learning History). Everything below was read from the code and
the schema, not assumed. **Nothing here is removed or rebuilt**: the new systems extend what exists.

## 1. What already exists (and is reused)

| Area | Where | What it already does |
|---|---|---|
| Runtime | `app/Core/*`, `app/Services/*`, PHP 8.1+ (no Composer), MySQL 5.7+/MariaDB 10.3+, no CTEs / window functions / JSON columns | PDO prepared statements only (`Db`), advisory locks (`Db::tryLock`), transactions (`Db::tx`), idempotency keys, fixed-window rate limiter, CSRF, signed admin sessions |
| Money | `app/Support/Money.php`, every money column | **Integer paise** everywhere (never floats); INR only, no currency selector |
| Time | `app/Support/Tz.php`, `Clock` | DATETIME columns are UTC; `*_day` DATE columns hold the **Asia/Kolkata** business day |
| Orders | `orders` (snapshots of service, price, cost, mode), `order_status_history`, `manual_orders` | 13 statuses, validated transitions (`Status`, `Orders::transition`), history rows with actor |
| Payments | `payments`, `webhook_events`, `Settlement`, `PaymentService`, `WebhookService` | create → QR → **signed webhook** and/or server-side `check-status`; settle under a row lock; amount + INR must match; UTR unique (`uq_payments_txn`); webhook de-duplication (`uq_webhook_dedupe`); late payments honoured and flagged; mismatch → `mismatch` (needs a human); nothing the browser sends is ever proof |
| Gateway | `app/Payments/*` (Alpha UPI, documented API: `create-order`, `check-status`, HMAC webhook) | **No refund API, no event id in the webhook, no fee in the response, no balance endpoint** (see §3) |
| Fulfilment | `Fulfilment`, `SmmProviders`, `smm_transactions` | AUTO: atomic claim `PAID→PROCESSING`, provider call logged, `unreachable` → back-off 1/5/20/60 min (max 5), `rejected` / `ambiguous` / `misconfigured` → `FAILED` for a human, **never blindly resubmitted**; MANUAL: queue + approve / approve-all (→ `MANUAL_APPROVED`, not "completed") |
| Profit | `Profit`, `Dashboard`, `Analytics`, `analytics_daily` | revenue − provider cost − ads; **MANUAL orders are treated as 100 % gross profit** (the gap §2 closes) |
| Ads | `Ads`, `advertisement_settings` (CPC history), `visitor_days` | ad spend = qualified visitors (≥ 3 s on site) × CPC at that moment, once per visitor per day — an **estimate**, not billed spend |
| Visitors | `Tracking`, `visitors`, `visitor_sessions`, `visitor_days` | first-party random id (no IP stored), sessions, 3-second qualification (server re-checks elapsed time), bot filter, UTM source / medium / campaign, funnel flags |
| Cron | `bin/cron.php`, `Cron::run` | one advisory-locked run: payments sweep, AUTO submit/sync, analytics refresh, purge |
| Admin | `PageController`, `routes_admin.php`, `resources/js/admin/*` | server shell + JSON API + JS page module; audit log (`audit_logs`); **one role, `owner`** (no authorisation checks beyond "signed in") |
| Settings | `admin_settings` (k / JSON v) | editable settings without code changes |
| Tests | `tests/run.php` (own runner), `tools/qa/*.mjs` (Playwright), mock gateway and SMM panel | 185 PHP tests, 20 browser suites |

## 2. Gaps this work closes

* **Profit**: no payment-fee, manual-fulfilment-cost, other-expense or chargeback records; unknown cost is silently 0; no cost-completeness label; no reconciliation view.
* **Payments**: no risk flags / thresholds, no monitoring screen with expected-vs-verified amounts, no safe reconciliation actions with permissions.
* **Recovery**: no recovery queue, no "last attempt" / recovery status, no reconcile action, no per-job locks or run history.
* **Operations**: no alerts table, no health checks beyond the cron sweep, no scheduled-job status in the admin.
* **Reports / AI / Support**: none exist.
* **Ads**: spend is only a CPC estimate; no actual-spend records, no attribution to orders, no `utm_content` / `utm_term`, no page-view or engaged-visitor counts.
* **Pricing**: one price per service; no experiments, cohorts, price versions or accepted-price snapshot beyond `orders.amount_paise`.
* **RBAC**: only `owner`.

## 3. What the payment gateway actually offers (from the project's documentation, not assumed)

| Capability | Alpha UPI | Consequence |
|---|---|---|
| Create payment | `POST /api/create-order` → `data.payment_url`, `token`, `amount`, `currency`, `expires_at` | hosted checkout URL only; the QR on our page is read from that page (see `docs/ORDERS.md` §3a) |
| Verify status | `POST /api/check-status` → `payment_status`, `utr`, `payment_method`, `amount`, `currency` | used server-side to reconcile pending / expired payments |
| Webhook | HMAC-SHA256 of the raw body, any header containing "signature" | verified in constant time; **no event id** → a SHA-256 of the body is the de-duplication key |
| Refunds | **none** | refunds are recorded by hand (`refunded_paise`); a `REFUND_PENDING` state is therefore **not supported** and not invented |
| Provider balance / health | **not exposed** | the Watchdog never reports a gateway balance; it reports only what our own records show |
| SMM panel | standard v2 `add` / `status` (+ `balance` where the panel offers it) | **no client reference or idempotency key**: an `add` that timed out cannot be looked up → such orders stay for a human (never auto-resubmitted) |

## 4. State vocabulary: the requested names mapped onto the existing, tested states

The existing values stay (they are stored in rows, used by the UI, 185 tests and the QA suites). The requested vocabulary is provided as a **mapping used in reports and monitoring screens** (`App\Support\Status::specPayment()` / `specOrder()`).

| Requested payment state | Existing | Note |
|---|---|---|
| CREATED | `creating` | |
| PENDING | `pending` | |
| PAID | `success` | only after server-side verification |
| FAILED | `failed` | |
| EXPIRED | `expired` | |
| REVIEW_REQUIRED | `mismatch` | amount / currency / duplicate reference |
| REFUNDED | `refunded`, `partially_refunded` | recorded by an admin |
| REFUND_PENDING | — | **not supported** (no refund API) |

| Requested order state | Existing |
|---|---|
| AWAITING_PAYMENT | `PENDING_PAYMENT` |
| PAID | `PAID` |
| QUEUED | `MANUAL_PENDING` (manual queue), `PAID` with `next_attempt_at` (waiting for a retry) |
| SUBMITTING | `PROCESSING` (claimed, row-locked) |
| PROCESSING | `SUBMITTED`, `IN_PROGRESS`, `MANUAL_APPROVED` |
| PARTIAL / COMPLETED / CANCELLED | `PARTIAL` / `COMPLETED` / `CANCELLED` |
| FAILED | `FAILED` |
| NEEDS_REVIEW | `FAILED` with `failure_kind` = `ambiguous` / `misconfigured`, or `recovery_status` = `needs_review` |

## 5. Implementation checklist (phases; each is committed separately)

- [x] **Phase 1** — this audit.
- [x] **Phase 2** — migrations (finance, risk, jobs, alerts, reports, support, ads, pricing), RBAC, audit helper, job runner, state mapping. *(commit `6259e03`)*
- [x] **Phase 3** — payment risk flags, thresholds, monitoring screen, safe reconcile actions, webhook cross-check, role enforcement on the existing admin. See [PAYMENT-CHECKS.md](PAYMENT-CHECKS.md).
- [x] **Phase 4** — recovery queue + actions (Admin → Order recovery), safe retry policy, per-job cron entry points with locks and run history (`php bin/cron.php recovery`), "run now" / dry run. See [ORDER-RECOVERY.md](ORDER-RECOVERY.md).
- [x] **Phase 5a** — Profit Intelligence: cost lines (fulfilment, payment fee, other), the fee model, expenses and chargebacks, the profit statement with *actual / estimated / incomplete* labels, reconciliation with append-only fixes, per-service fulfilment cost, role-gated profit (Admin → Finance). See [PROFIT-INTELLIGENCE.md](PROFIT-INTELLIGENCE.md).
- [x] **Phase 5b** — Ads Intelligence: campaign tracking (five tags, page views, engaged, returning), attribution of verified orders (last non-direct touch, configurable window, stored once), actual vs estimated spend, campaign metrics with confidence labels, spend entry / CSV import (Admin → Ads Intelligence). See [ADS-INTELLIGENCE.md](ADS-INTELLIGENCE.md).
- [ ] **Phase 6** — Business Watchdog, alerts, deterministic Daily Report.
- [ ] **Phase 7** — AI adapter (optional), AI commentary, Support Assistant (rules-based; AI optional).
- [ ] **Phase 8** — Adaptive pricing: settings & budget maths, price rules, cohorts, quotes, experiments, rollback; Simulator, Profit Guard, Learning History.
- [ ] **Phase 9** — admin integration, cron documentation, tests, regression, security checks, deployment notes.

Status of each item, the tests that were actually run, and the known limits are reported in `CHANGELOG.md` and `docs/QA-REPORT.md`
when each phase lands. A feature is only listed as *implemented* when its tests ran.
