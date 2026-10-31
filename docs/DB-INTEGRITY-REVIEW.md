# DB integrity review — Priorities 1–4

Priority 5 of the master prompt: a review of every schema addition made in Priorities 1–4, for referential integrity, indexing,
concurrency safety and consistency with this codebase's own conventions. Priorities 2 and 4 added no schema (Appearance & Themes and
the Watchdog checks are both settings-only — see [DATABASE.md](DATABASE.md)), so the review covers migrations **013** and **014**.

## 1. What was reviewed

* **Migration `013_order_calculator`** — `services.pricing_model` / `pricing_unit_qty` / `provider_qty_divisor` / `config_version`,
  `orders.provider_quantity` / `pricing_model` / `config_version`.
* **Migration `014_sales_target_ad_budget`** — the new `sales_targets` table, and the first real use of `ad_campaign_settings`
  (provisioned by migration `009_adaptive_pricing` but unused until Priority 3).

For each: column types and nullability, indexes against the queries actually run (`EXPLAIN`), foreign-key / orphan-row risk, write-path
concurrency, and migration/rollback correctness, cross-checked against the conventions already established elsewhere in this schema
(`database/schema.sql`, `docs/DATABASE.md`).

## 2. One real issue found and fixed: a TOCTOU race in the new "revoke-on-overlap" writers

**`App\Services\SalesTarget::set()` and `App\Services\AdBudget::save()` both follow a "find rows this save would supersede, then revoke
them and insert the new one" pattern** — the same supersede-on-overlap design already used for `ad_campaign_settings` and now
`sales_targets`. The first version read the candidate rows to revoke in a plain `SELECT` **before** opening the write transaction.

Two admins saving at (nearly) the same instant could both run that `SELECT` before either had committed, both see the *same* existing
row as the one to supersede, and both then revoke-and-insert inside their own transaction — leaving **two** non-revoked rows where
exactly one was intended. Nothing about money was wrong (both rows have genuinely-entered values and `current()`'s own
`ORDER BY created_at DESC LIMIT 1` still resolves "today" to a single, correct answer either way), but the invariant "at most one live
row per kind/period" — the one the whole supersede design exists to guarantee — could be silently broken, and `history()` would show a
confusing pair of simultaneously-"live" rows until the next save cleaned it up.

This codebase already has an established, repeatedly-used pattern for exactly this shape of problem — lock the rows that decide the
outcome for the rest of the transaction (`SELECT … FOR UPDATE`), used throughout `Finance`, `Fulfilment`, `Orders`, `PaymentService` and
`Settlement` for payment- and order-state writes. **The fix moves the candidate `SELECT` inside the transaction and adds `FOR UPDATE`**,
so a second concurrent save blocks until the first one commits, then sees the row(s) already revoked and computes a different (or
empty) set to supersede — the same discipline the rest of the codebase already applies to every other concurrency-sensitive write, now
extended to these two.

```php
// before (racy): the SELECT happens before the lock exists
$superseded = Db::col('SELECT id FROM sales_targets WHERE revoked_at IS NULL AND period_start <= ? AND period_end >= ?', [$end, $start]);
Db::tx(function () use (...) { /* revoke $superseded, then insert */ });

// after: the SELECT holds the row lock for the rest of the transaction
Db::tx(function () use (..., &$superseded) {
    $superseded = Db::col('… FOR UPDATE', [$end, $start]);
    /* revoke $superseded, then insert */
});
```

**Verified as a real race, not a theoretical one**: `tests/Integration/SalesTargetTest.php` and `tests/Integration/AdBudgetTest.php` each
gained a test that spawns four independent PHP processes (`tests/fixtures/sales_target_worker.php` /
`ad_budget_worker.php`, the exact same multi-process pattern `tests/Integration/RecoveryTest.php` already uses for its own
same-instant-race tests), synchronized on a "go" file so all four race through `set()` / `save()` together. Reverting the fix and
widening the race window with a deliberate `usleep()` reproduced the failure deterministically (all four rows ended up live, 0
revoked); with the fix, every run leaves exactly one live row, and `current()` agrees with it.

## 3. Everything else: reviewed, no changes needed

* **Foreign keys on `set_by` (both new/touched tables).** Neither `sales_targets.set_by` nor `ad_campaign_settings.set_by` has a FK to
  `admins.id` — this matches every other audit-trail "who did this" column in the schema (`price_audit_logs.created_by`,
  `business_alerts.acknowledged_by` / `resolved_by`, `ad_daily_spend.entered_by`, the pre-existing `ad_campaign_settings.set_by` itself,
  …): none of them are FK-constrained. `admins` rows are never hard-deleted anywhere in the codebase (only soft-deactivated via
  `is_active`), so there is no real orphan risk, and leaving these unconstrained is the established, deliberate pattern — not an
  oversight to fix.
* **No `CHECK` constraints.** Nothing in `database/schema.sql` uses them; every invariant (date ranges, positive amounts, valid enum
  values) is enforced in PHP with a `422` and a field-level message, exactly like `SalesTarget::set()` and `AdBudget::save()` already
  do. Consistent, not a gap.
* **Indexing.** `idx_sales_targets_period (period_start, period_end, revoked_at)` is used by `EXPLAIN` as a covering range scan
  (`type: range`, `Extra: Using index`) for the exact query `current()`/`set()` run. `ad_campaign_settings`'s existing
  `idx_ad_campaign_kind (kind, created_at)` is unchanged and adequate for the same reason the original Phase-8 design left it as-is:
  both tables grow by one row per admin-initiated save — realistically dozens to low hundreds of rows over a shop's lifetime, never a
  scale where index shape matters.
* **Column types and ranges.** `sales_targets.target_paise` and `ad_campaign_settings`'s JSON-encoded budget figures are `INT UNSIGNED`
  (max ~42.9 million rupees), comfortably above the application-level caps (`SalesTarget::MAX_TARGET_PAISE` ₹1,00,00,000,
  `AdBudget::MAX_DAILY_PAISE`/`MAX_MONTHLY_PAISE`) with no overflow path. `period_start`/`period_end` are `DATE` (not `DATETIME`),
  matching `orders.created_day`/`paid_day`'s existing convention for business-timezone calendar days.
* **`services.config_version` / `orders.config_version` vs. the pre-existing adaptive-pricing versioning
  (`service_price_versions`, `price_audit_logs`, `orders.price_version_id`).** Grepped for any overlap: `App\Services\Pricing` never
  reads `config_version`, and `App\Services\OrderCalculator` never reads the adaptive-pricing version columns. The two systems track
  genuinely different things (OrderCalculator's own pricing *shape* — model/quantity/divisor — vs. adaptive pricing's *which price a
  visitor was shown*) and don't collide.
* **`config_version` bump race in `AdminServices::save()`** (the one *existing* read-then-increment pattern touching a Priority-1
  column): already protected by the pre-existing optimistic-concurrency check (`expected_updated_at` vs. the row's real
  `updated_at`, `409 conflict` on mismatch) that gates every field of a service edit, `config_version` included — a second concurrent
  edit is rejected outright rather than racing. No change needed.
* **`OrderCalculator`'s read of a service's pricing columns at checkout.** Read as one atomic `SELECT` (`Catalog::orderable()`), and the
  whole checkout path is already serialized per visitor+service+target by a named advisory lock (`Db::tryLock`) — pre-existing,
  tested in Priority 1's own phase, untouched here.
* **Orphan check against the live dev database**: zero orphaned `set_by` references, zero `per_unit` services with a `NULL`
  `pricing_unit_qty`, zero `provider_qty_divisor = 0` rows, zero `per_unit` orders missing a computed `provider_quantity` outside the
  pre-submission statuses.
* **Migration / rollback correctness**: `php bin/migrate.php` applies cleanly from a fresh database; `tools/build-schema.php --check`
  confirms `database/schema.sql` (the all-in-one import for phpMyAdmin-style installs) exactly matches the migration files; both
  migrations' documented rollback blocks were re-checked against what they actually created.

## 4. Tests

2 new concurrency tests (`tests/Integration/SalesTargetTest.php`, `tests/Integration/AdBudgetTest.php`), each driving four real,
independent PHP processes through the fixed code and asserting the invariant holds (exactly one live row; `current()`/the save's own
return value agree with it); 2 new worker fixtures (`tests/fixtures/sales_target_worker.php`, `ad_budget_worker.php`) following the
existing `recovery_worker.php` pattern. Full PHP battery rerun clean after the fix.
