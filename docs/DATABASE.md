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
| `services` | The sellable packs: `code` (admin-visible Service ID, unique), `platform_id`, `type` (followers\|likes\|views\|verification\|subscribers\|watch_time), `target_kind` (profile\|post\|video\|channel — what the customer must enter), `title`, `subtitle`, `price_paise`, `quantity` (+ `min_quantity` / `max_quantity`), **`pricing_model`** (fixed_package\|per_unit — migration `013`, see `App\Services\OrderCalculator`), **`pricing_unit_qty`** (per_unit only: how many units `price_paise` buys; the customer may request any whole multiple), `mode` (AUTO\|MANUAL), `smm_provider_id`, `smm_service_id`, `smm_cost_paise` (what the provider charges for **one** purchase), **`provider_qty_divisor`** (converts the customer's unit count into the provider's billing unit, default 1 — same units), **`config_version`** (bumped whenever a pricing-relevant field changes; every order snapshots the version it was priced against), `tag_id` (the assigned badge → `service_tags`; NULL = no tag; the old `badge` / `badge_color` columns are kept but no longer read), `icon_path`, `is_premium`, `status` (active\|inactive), `sort_order`, `deleted_at` (soft delete; the code is freed). |
| `service_tags` | The badges (**Admin → Services → Tags & Badges**): `code` (stable key of the 10 predefined tags — `trial-pack`, `best-seller`, `popular`, `best-value`, `premium`, `hot-deal`, `non-drop`, `instant`, `limited-offer`, `vip`; NULL for custom tags — the automatic ₹5 rule is bound to `trial-pack`, never to a name), `name` (unique, case-insensitive), `bg_color` / `text_color` (`#RRGGBB`, upper case, validated), `is_active` (a disabled tag is hidden on the store but keeps its assignments), `is_system` (predefined: editable and disableable, not deletable), `sort_order`. `services.tag_id` → `service_tags.id` is `ON DELETE SET NULL`, so deleting a custom tag leaves no dangling reference. |
| `service_pricing` | Append-only history of price / provider-cost changes (who, when). |
| `smm_providers` | Supplier panels: `api_url`, `api_key_enc` (**AES-256-GCM**), `currency` / `rate_to_inr_micros` (**legacy**: always `INR` / `1000000` — the shop is INR-only and nothing reads them; they are rewritten to those values when a provider is saved), `is_active`, `is_default`, last test result and balance. |
| `payment_gateways` | `provider` (`alpha_upi`), `environment` (test\|production), `config_json` (non-secret: `base_url`, `api_style` = headers\|bearer, `provider_pref`), `secrets_enc` (**encrypted JSON**: `api_key`, `api_secret`, `webhook_secret`), `is_active`, last test result. |

### Orders & payments
| Table | Purpose / notable columns |
|---|---|
| `orders` | One row per purchase. Identity: `public_id` (`SMB-XXXXXXXX`, shown to customers), `access_token` (128-bit capability for `/order/{token}`). Snapshots: `service_*`, `mode`, `quantity`, **`provider_quantity`** (exact quantity sent to the SMM provider — NULL on pre-migration-`013` rows, where it always equalled `quantity`), **`pricing_model`**, **`config_version`** (the `services.config_version` this order was priced against — see `App\Services\OrderCalculator`), `amount_paise`. `target` (what the customer typed, control characters stripped) vs `target_url` (validated + normalised; what the provider receives). `status` (13 values), `payment_status`, `refunded_paise`, `late_payment`. Fulfilment: `smm_provider_id`, `smm_service_id`, `provider_order_id`, `provider_status`, `estimated_cost_paise` → `provider_cost_paise` (+ `provider_cost_final` once the provider's real charge is known), `start_count`, `remains`, `provider_attempts`, `next_attempt_at`, `last_error`, `failure_kind` (rejected\|unreachable\|ambiguous\|misconfigured), `admin_note`. Timeline: `created_day`, `paid_day`, `paid_at`, `submitted_at`, `completed_at`. Attribution: `user_id`, `visitor_id`, `visitor_session_id`. |
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

### Operations, risk, finance, ads, reports and pricing (migrations 005–012)
Additive only: nothing above was changed or removed (the new columns are nullable or default-off). Rollback notes are at the top of each migration file.

| Table / columns | Purpose |
|---|---|
| `job_runs` *(006)* | One row per run of a scheduled job: `job`, `trigger_source` (cron\|admin\|web), `dry_run`, `status` (running\|ok\|failed\|timeout\|abandoned), start / finish / duration, small `result_json`, `error_category` (database\|network\|timeout\|logic) and a sanitised message — never a stack trace, path or secret. Kept 90 days. |
| `business_alerts` *(006)* | What the watchdog (and the payment checks) found — written by the Business Watchdog since Phase 6 ([WATCHDOG-AND-REPORTS.md](WATCHDOG-AND-REPORTS.md)). `dedupe_key` (`wd:<check>[:<suffix>]`) + a unique `active_key` (set while open / acknowledged, `NULL` once resolved) → **one live alert per problem**; `occurrences`, severity (info\|warning\|critical), first / last detected, acknowledged / resolved by, `resolution` (auto\|admin), `last_notified_at` (e-mail cooldown). Title ≤ 160 and description ≤ 1,000 characters, cleaned of paths and long tokens. |
| `payment_flags` *(006)* | Suspicious-payment findings ([PAYMENT-CHECKS.md §4](PAYMENT-CHECKS.md)): `flag_type`, `severity` (low\|medium\|high), `payment_id`, `order_id`, `gateway_id`, `webhook_event_id`, `dedupe_key` + unique `active_key` (one open flag per problem), `status` (open\|reviewed\|dismissed\|resolved), `occurrences`, first / last seen, `details_json` (plain facts only), `reviewed_by` / `reviewed_at` / `review_note`. Closed flags are deleted after a year. |
| `orders.last_attempt_at`, `recovery_status`, `escalated_at`, `escalated_by` *(006)* | Recovery bookkeeping, used by the recovery queue ([ORDER-RECOVERY.md](ORDER-RECOVERY.md)): `recovery_status` = `auto_retry` (a scheduled retry) · `needs_review` · `escalated` (who / when in `escalated_by` / `escalated_at`) · `resolved`; with the older `provider_attempts`, `next_attempt_at`, `last_error` and `failure_kind` (`unreachable` · `rejected` · `ambiguous` · `misconfigured`). The queue itself is **not stored**: every category is derived from these columns on each request. The retry limit is the setting `recovery.max_attempts` (1–10, default 5) in `admin_settings`. |
| `webhook_events.last_attempt_at` *(010)* | When the latest repeated delivery of an event arrived (`attempts` counts every delivery). |
| `services.fulfil_cost_paise`, `order_costs`, `expenses` *(005)* | What delivering a MANUAL order costs (NULL = not stated → profit is labelled incomplete), append-only per-order cost lines (`fulfilment`, `payment_fee`, `other`; a correction ends the old row), and business expenses / chargebacks by IST day. |
| `analytics_daily.fulfil_cost_paise / payment_fee_paise / other_cost_paise / chargebacks_paise / expenses_paise / ads_actual_paise / ads_estimated_paise / ads_actual_day / orders_cost_estimated / orders_cost_incomplete` *(011)* | The per-day sums behind the Finance statement ([PROFIT-INTELLIGENCE.md](PROFIT-INTELLIGENCE.md)): the order cost lines of the orders paid that day, chargebacks and expenses recorded that day, advertising split into the actual and the estimated part, and how many of that day's paid orders have estimated / incomplete costs. Derived data: the migration marks every stored day dirty and the next read recomputes it. |
| `daily_reports` *(007)* | One deterministic report per finished IST day, written by the report job or by an admin ([WATCHDOG-AND-REPORTS.md](WATCHDOG-AND-REPORTS.md)): `report_date` (**UNIQUE** — a second run can never make a second row), `calc_version`, `snapshot_json` (integers only: every figure with its value, unit, basis and note; the comparison with the previous day; services; failures; the alerts unresolved when it was built; the rule-written highlights; the caveats), `generated_at`, `generated_by` (cron\|admin), `regenerated_count`, and the AI columns (`ai_status`, `ai_provider`, `ai_model`, `ai_summary`, `ai_generated_at`) that stay empty until the optional AI step exists and are cleared whenever a rebuild changes a figure. Kept; reports are never purged. |
| `ai_usage_logs` *(007)* | One row per call to the optional AI provider, written by `App\Services\AiService` since Phase 7 ([AI-AND-SUPPORT.md](AI-AND-SUPPORT.md)): `feature` (`report`\|`support`\|`test`), `provider`, `model`, `status` (`ok`\|`error`\|`invalid`\|`limited`), `http_status`, `input_tokens`/`output_tokens` when the provider reports them, `latency_ms`, `error_category` — never the prompt, the answer, or the key. |
| `support_conversations`, `support_messages` *(007)* | A customer support chat since Phase 7: one conversation per random 128-bit key the browser keeps (no account), `status` (open\|closed), `message_count`, `ai_messages`, `expires_at` (purged by the `cleanup` job past this — `support.retention_days`, default 30). Each message: `role` (customer\|assistant), `body`, the matched `intent`, `mode` (`rules`\|`ai` — a reply that resolved a specific order is always `rules`), and the internal `order_id` a reply was about, if any (never the customer's token, which is never stored). |
| `visitor_sessions.utm_content / utm_term / page_views / service_views / engaged_at / returning_visitor`, `ad_daily_spend`, `campaign_attribution` *(008)* | Finer visitor measurement, **actual** ad spend per IST day (pre-tax and GST apart; the CPC estimate is never added on top of a day that has actual spend) and one attribution row per verified order. |
| `visitor_days.utm_campaign` *(012)* | The campaign the visitor-day belongs to: the first campaign-tagged session of that day, written once (NULL = no campaign). It lets visitors, qualified visitors, checkout starts and the CPC estimate split by campaign add up exactly to the dashboard ([ADS-INTELLIGENCE.md](ADS-INTELLIGENCE.md)). Existing rows are filled in best effort from the sessions that still exist. |
| `service_price_versions`, `pricing_experiments`, `pricing_experiment_cohorts`, `pricing_assignments`, `pricing_decision_logs`, `price_audit_logs`, `ad_campaign_settings`, `services.adaptive_*`, `services.pricing_paused`, `orders.price_version_id / experiment_id / price_cohort`, `visitor_sessions.platform_pref` *(009)* | **Adaptive pricing** (the tables; the engine itself — `App\Services\Pricing` — is Phase 8, [ADAPTIVE-PRICING.md](ADAPTIVE-PRICING.md)): `services.price_paise` stays the single source of truth for the default price; every order keeps the exact price it was shown (`orders.amount_paise` + `price_version_id` / `experiment_id` / `price_cohort`, never rewritten); `pricing_experiments` one controlled control-vs-candidate test per service (status, mode `dry_run`\|`live`, decision, rollback); `pricing_assignments` a keyed-hash-of-the-visitor's-own-cookie → cohort, stable for its lifetime, never an IP; `pricing_experiment_cohorts` the evaluator's own rollups; `pricing_decision_logs` every evaluation, dry runs included; `price_audit_logs` every price change, by any actor. `ad_campaign_settings` is unused by this release (deliberately deferred, see ADAPTIVE-PRICING.md §8). Everything defaults to **off**: a service needs `adaptive_enabled = 1` *and* a configured fulfilment cost, an experiment starts in `mode = dry_run` (a real A/A test — nobody is shown a different price yet), and only `goLive()` (owner-only) can change that. |

### Settings keys written by the watchdog and the e-mail (`admin_settings`, Phase 6 — no migration)
| Key | Value |
|---|---|
| `watchdog.<name>` | One integer per threshold (30 of them: `pay_fail_pct`, `pay_fail_min`, `webhook_invalid`, `provider_error_pct`, `stuck_minutes`, `cost_rise_pct`, `sales_drop_pct`, `conv_change_pct`, `job_failures`, `app_errors`, `balance_min`, `clean_runs`, …). Each has a unit, a default and **hard bounds**; a saved value is validated as a whole and audited with before and after. Missing = the default. |
| `watchdog.enabled.<check>` | `false` switches a check off (it then does not run; its live alert is not quietly closed). `provider_balance` is **off by default** (it asks the SMM provider). |
| `watchdog.state` | The watchdog's own memory: per check the last verdict (`ok\|alert\|insufficient\|unavailable\|disabled`), a summary of at most 500 characters, the time, the clean-run counter per alert key and the provider-balance check's last answer. Written by the job, never by a person. |
| `alerts.email_enabled`, `alerts.email_to`, `alerts.email_from`, `alerts.email_min_severity`, `alerts.email_cooldown_min` | The optional alert e-mail (off by default; at most 5 recipients; minimum severity `warning` or `critical`; cooldown 5–1,440 minutes). |

### Settings keys written by the AI provider and the Support Assistant (`admin_settings`, Phase 7 — no migration)
| Key | Value |
|---|---|
| `ai.enabled`, `ai.provider` (`none`\|`anthropic`\|`openai_compatible`), `ai.model`, `ai.base_url` | Off by default. The one optional AI provider — see [AI-AND-SUPPORT.md](AI-AND-SUPPORT.md). |
| `ai.api_key_enc` | The provider's API key, AES-256-GCM encrypted (same scheme as the payment gateway and SMM provider secrets). Write-only: no API ever returns it, only `has_key: true/false`. |
| `ai.use_for_reports`, `ai.use_for_support`, `ai.use_for_pricing` | Three independent switches (all off by default): whether the daily report's optional AI commentary, the support assistant's order-free answers, and the pricing engine's *advisory* insight ([ADAPTIVE-PRICING.md](ADAPTIVE-PRICING.md)), may ever be sent to the provider. |
| `support.enabled` | Default `true`. Switches the whole customer chat off (`404` on every `/api/support/*` route) without touching the AI settings. |
| `support.retention_days` | Default 30, 1–365. How long a conversation (and its messages) survive before the `cleanup` job deletes them. |

### Settings keys written by the adaptive pricing engine (`admin_settings`, Phase 8 — no migration)
| Key | Value |
|---|---|
| `pricing.global_pause` | Default `false`. The emergency brake: while on, no visitor is newly assigned to any experiment (an already-placed order is never touched) — see [ADAPTIVE-PRICING.md](ADAPTIVE-PRICING.md). |
| `pricing.cooldown_days` | Default 14. How long a service stays ineligible for a new experiment after one concludes or rolls back. |
| `pricing.assignment_ttl_days` | Default 30. How long a visitor's cohort assignment lives before it is re-derived (same visitor → same cohort, deterministically, so this never looks like a new assignment). |
| `pricing.default_treatment_share_bps`, `pricing.default_min_sample`, `pricing.default_max_duration_days`, `pricing.min_price_paise` | Defaults a new experiment is proposed with (50 %, 200, 21 days, ₹20) unless the admin states otherwise. |
| `pricing.rollback_conversion_tolerance_bps`, `pricing.rollback_failure_gap_bps`, `pricing.cost_increase_tolerance_bps`, `pricing.decision_margin_bps`, `pricing.max_price_change_bps` | The automatic rollback and winner/loser thresholds (§5–6 of ADAPTIVE-PRICING.md). |

## Relationships (simplified)

```
platforms 1─∞ services ∞─1 smm_providers        service_tags 1─∞ services (tag_id, ON DELETE SET NULL)
services  1─∞ orders  1─∞ payments ∞─1 payment_gateways
orders    1─∞ order_status_history        orders 1─∞ smm_transactions      orders 1─1 manual_orders
visitors  1─∞ visitor_sessions            visitors 1─∞ visitor_days        visitors 1─1 users 1─∞ orders
```
Foreign keys protect history: a service or platform that has orders cannot be hard-deleted (services are soft-deleted); deleting a provider only nulls the reference on services/orders.

## Migrations & seeding

* `database/migrations/NNN_name.sql` are applied in order by `Migrator` (`php bin/migrate.php`, the web installer, or **Admin → System → Apply database updates**) and recorded in `schema_migrations`. Scripts are split on `;` aware of quotes and comments.
* `database/seeds/catalog.php` seeds the default catalogue (exactly the 14 services of the brief, prices in paise, **all in MANUAL mode** so a fresh install never calls a provider), the two platforms, the gateway placeholder row and default settings. Seeding is idempotent (`php bin/seed.php`).
* After changing a migration, regenerate the full import with `php tools/build-schema.php`.
* `002_trial_pack_is_automatic.sql` clears any stored `TRIAL PACK` badge text (the badge is derived from the price, a stored copy would only go stale).
* `003_service_tags.sql` creates `service_tags` + `services.tag_id`, inserts the 10 predefined tags (TRIAL PACK `#DC2626`, BEST SELLER `#7C3AED`, POPULAR `#D97706`, BEST VALUE `#15803D`, PREMIUM `#0369A1`, HOT DEAL `#BE185D`, NON DROP `#0F766E`, INSTANT `#4338CA`, LIMITED OFFER `#475569`, VIP `#A16207`; all with white text) and converts the old free-text badges: text that names a predefined tag is assigned to it, any other text becomes a custom tag coloured like the old colour name; `TRIAL PACK` text is never assigned (the tag is automatic). Nothing is dropped. **Money comparison:** the storefront decides *TRIAL PACK* with `Tags::isTrialPrice()` — `price_paise === 500`, an integer comparison, so there is no floating-point doubt (₹4.99 = 499 and ₹5.01 = 501 are not trial prices). The tag is one row, so seeding (`Tags::seedPredefined()`, also run by `bin/seed.php`) and the migration both use `INSERT IGNORE` and never overwrite an admin's edit; a test keeps the PHP list, the seeder and the SQL in agreement, and another one that `database/schema.sql` is the current build of the migrations (`php tools/build-schema.php --check`).

## Retention
Cron purges rate-limit and idempotency rows, expired admin sessions, webhook events older than 180 days, job history older than 90 days, closed payment flags older than a year, support conversations past their retention, log files older than 30 days and raw visitor sessions older than 400 days. Money-relevant aggregates (`orders`, `payments`, `visitor_days`, `analytics_daily`) are kept, and so are the daily reports and the resolved business alerts (the history of what happened; one alert per problem keeps it small).
