# Database

MySQL 5.7+ / MariaDB 10.3+, InnoDB, `utf8mb4`. No CTEs, window functions, `CONVERT_TZ()` or JSON column types are used, so it runs on ordinary cPanel shared hosting. The whole QA battery (PHP tests, installer, browser suites, failure injection, security audit) has been run on **MariaDB 10.11 and MySQL 8.0**; 5.7 and the older MariaDB series are supported by design (nothing newer is used) but were not run. The authoritative definition is **[`database/schema.sql`](../database/schema.sql)** (generated from the migrations by `tools/build-schema.php`; import it into an empty database, or let the installer create the tables).

## Conventions (read these first)

| Rule | Why |
|---|---|
| **Money is `INT`/`BIGINT` paise** (₹1 = 100 paise). Columns are `UNSIGNED` where a negative value is meaningless — the schema itself refuses a negative price or quantity. | No floating-point rounding anywhere in accounting. Percentages are stored/handled as integer *tenths*. |
| **All `DATETIME` are UTC.** The connection runs `SET time_zone = '+00:00'`. | Independent of the host's MySQL time zone. |
| **`*_day` `DATE` columns hold the *business-time-zone* day** (default `Asia/Kolkata`), computed by the application when the row is written. | "Today" in reports is the shop's today. No time-zone tables or `CONVERT_TZ()` needed. The time zone is locked once orders or visitor-days exist (re-bucketing would corrupt history). |
| Statuses are `VARCHAR`, not `ENUM`. The allowed values live in `app/Support/Status.php` (a state machine enforces transitions). | New statuses never need `ALTER TABLE`. |
| Indexed `VARCHAR`s ≤ 191 chars. | Safe on older InnoDB row formats. |
| **Snapshots:** an order copies the service title, price, quantity, mode, provider ids and estimated cost at creation. | Editing or deleting a service never rewrites history or profit. |
| Strict SQL mode (`STRICT_TRANS_TABLES, ONLY_FULL_GROUP_BY, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO`) plus `NO_UNSIGNED_SUBTRACTION` is set on every connection (so `revenue − cost` of UNSIGNED money columns is a negative number — a loss — instead of an "out of range" error on MySQL 8). Lock waits are bounded (`lock_wait_timeout=10`, `innodb_lock_wait_timeout=8`; MariaDB also `max_statement_time=25` for web requests). | Deterministic behaviour on every host; a stuck lock fails fast with a friendly 503 instead of hanging a PHP worker. |

## Tables

### Catalogue & integrations
| Table | Purpose / notable columns |
|---|---|
| `platforms` | Instagram, YouTube (and any you add): `slug`, `name`, `tagline`, `logo_path` (admin upload; NULL = built-in SVG), `is_active`, `sort_order`. |
| `services` | The sellable packs: `code` (admin-visible Service ID, unique), `platform_id`, `type` (followers\|likes\|views\|verification\|subscribers\|watch_time), `target_kind` (profile\|post\|video\|channel — what the customer must enter), `title`, `subtitle`, `price_paise`, `quantity` (+ `min_quantity` / `max_quantity`), `mode` (AUTO\|MANUAL), `smm_provider_id`, `smm_service_id`, `smm_cost_paise` (what the provider charges for **one** purchase), `badge`, `badge_color`, `icon_path`, `is_premium`, `status` (active\|inactive), `sort_order`, `deleted_at` (soft delete; the code is freed). |
| `service_pricing` | Append-only history of price / provider-cost changes (who, when). |
| `smm_providers` | Supplier panels: `api_url`, `api_key_enc` (**AES-256-GCM**), `currency`, `rate_to_inr_micros` (1 provider-currency unit in ₹ × 10⁶, used to convert the provider's charge), `is_active`, `is_default`, last test result and balance. |
| `payment_gateways` | `provider` (`alpha_upi`), `environment` (test\|production), `config_json` (non-secret: base URL…), `secrets_enc` (**encrypted JSON**: API key, webhook secret), `is_active`, last test result. |

### Orders & payments
| Table | Purpose / notable columns |
|---|---|
| `orders` | One row per purchase. Identity: `public_id` (`SMB-XXXXXXXX`, shown to customers), `access_token` (128-bit capability for `/order/{token}`). Snapshots: `service_*`, `mode`, `quantity`, `amount_paise`. `target` (what the customer typed, control characters stripped) vs `target_url` (validated + normalised; what the provider receives). `status` (13 values), `payment_status`, `refunded_paise`, `late_payment`. Fulfilment: `smm_provider_id`, `smm_service_id`, `provider_order_id`, `provider_status`, `estimated_cost_paise` → `provider_cost_paise` (+ `provider_cost_final` once the provider's real charge is known), `start_count`, `remains`, `provider_attempts`, `next_attempt_at`, `last_error`, `failure_kind` (rejected\|unreachable\|ambiguous\|misconfigured), `admin_note`. Timeline: `created_day`, `paid_day`, `paid_at`, `submitted_at`, `completed_at`. Attribution: `user_id`, `visitor_id`, `visitor_session_id`. |
| `payments` | One payment session per order: `ref` (128-bit capability for `/pay/{ref}`), `gateway_id`, `gateway_order_id` (the id we send to the gateway), `provider_txn_id` (UTR / bank reference — unique per gateway), `amount_paise`, `status` (creating\|pending\|success\|failed\|expired\|mismatch\|refunded\|partially_refunded), `payment_url`, `qr_payload`, `expires_at` (created + 5:00), `paid_at`, `paid_amount_paise`, `late`, `verified_via` (webhook\|poll\|admin), `refunded_paise`, `poll_count` / `last_polled_at` (gateway lookups are rate-limited per payment). |
| `order_status_history` | Append-only timeline: from → to, actor (customer\|system\|webhook\|provider\|admin), note, optional meta. Informational notes (e.g. "Duplicate payment received …") reuse the current status as both from and to. |
| `smm_transactions` | Every call to a provider (`add`, `status`, `balance`…): request JSON (**never the API key**), response excerpt, HTTP status, success flag, duration, provider order id, charge. |
| `manual_orders` | Queue of paid MANUAL orders waiting for an admin (`state` pending\|approved…). |
| `webhook_events` | Every inbound webhook: signature validity, payload hash and excerpt, status (received\|processed\|ignored\|mismatch\|invalid\|error), attempts, IP. `dedupe_key` is set **only** for verified events (unique) so a forged event can never use up a real event's id. |
| `users` | Anonymous customers: one per visitor that ever ordered (`orders_count`, `paid_orders_count`, `total_spent_paise`). No names, emails or phone numbers are collected. |

### Visitors & analytics
| Table | Purpose |
|---|---|
| `visitors` | One row per first-party cookie (`anon_id`, 400 days): first source/referrer host, coarse device/browser/OS, `has_order`, `has_paid`. **No IP address, no fingerprint, no raw user-agent.** |
| `visitor_sessions` | One per visit (idle 30 min starts a new one): UTM fields, `active_seconds`, `qualified_at`, `platform_viewed`, funnel flags. |
| `visitor_days` | **The billing unit.** One row per visitor per business day (`UNIQUE (visitor_id, day)`); `qualified` + `cpc_paise` (the CPC **snapshot** charged when the visitor stayed ≥ the qualify time — once per day however many refreshes). |
| `analytics_daily` | Pre-aggregated per-day rollup (revenue, refunds, SMM spend, gross/net profit, AUTO/MANUAL split, payments by outcome, visitors by device/source, ad spend). `dirty` marks days to recompute; reads call `Analytics::ensureFresh`, cron refreshes the last 3 days. |
| `advertisement_settings` | Append-only history of CPC (`cpc_paise`, default 108), `qualify_seconds` (default 3) and the assumed conversion rate used for profit estimates; the newest row is current. |

### Admin, security & infrastructure
| Table | Purpose |
|---|---|
| `admins` | `username`, `email`, `password_hash` (Argon2id, or bcrypt-12 over a SHA-256 prehash), `must_change_password`, `failed_logins`, `locked_until`, last login. |
| `sessions` | Admin sessions: **SHA-256 of the cookie token** (the token itself is never stored), per-session CSRF secret, idle + absolute expiry, `revoked_at`. |
| `admin_settings` | Key/value site settings (JSON-encoded values): `site.name`, `site.maintenance`, `business.timezone`, `cron.last_run`… Missing keys fall back to defaults in `Settings::defaults()`. |
| `audit_logs` | Who did what, when, from which IP — field **names** only, never values of secrets. |
| `idempotency_keys` | Double-submit protection (checkout, Approve All, admin saves): key hash, request hash, state, stored response. |
| `rate_limits` | Fixed-window counters `k = sha1(bucket|identity|window)`; purged by cron. |
| `schema_migrations` | Applied migration versions. |

## Relationships (simplified)

```
platforms 1─∞ services ∞─1 smm_providers
services  1─∞ orders  1─∞ payments ∞─1 payment_gateways
orders    1─∞ order_status_history        orders 1─∞ smm_transactions      orders 1─1 manual_orders
visitors  1─∞ visitor_sessions            visitors 1─∞ visitor_days        visitors 1─1 users 1─∞ orders
```
Foreign keys protect history: a service or platform that has orders cannot be hard-deleted (services are soft-deleted); deleting a provider only nulls the reference on services/orders.

## Migrations & seeding

* `database/migrations/NNN_name.sql` are applied in order by `Migrator` (`php bin/migrate.php`, the web installer, or **Admin → System → Apply database updates**) and recorded in `schema_migrations`. Scripts are split on `;` aware of quotes and comments.
* `database/seeds/catalog.php` seeds the default catalogue (exactly the 14 services of the brief, prices in paise, **all in MANUAL mode** so a fresh install never calls a provider), the two platforms, the gateway placeholder row and default settings. Seeding is idempotent (`php bin/seed.php`).
* After changing a migration, regenerate the full import with `php tools/build-schema.php`.

## Retention
Cron purges rate-limit and idempotency rows, expired admin sessions, webhook events older than 180 days, log files older than 30 days and raw visitor sessions older than 400 days. Money-relevant aggregates (`orders`, `payments`, `visitor_days`, `analytics_daily`) are kept.
