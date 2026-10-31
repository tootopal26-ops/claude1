# Sales Target & Ad Budget Intelligence

Priority 3 of the master prompt — two planning tools on **Admin → Advertising**, alongside the existing CPC settings:

* **Sales Target** (`App\Services\SalesTarget`) — a periodic revenue goal and real pacing against it: actual revenue so far, a projection
  to the end of the period, and the daily pace still needed.
* **Ad Budget & Forecast** (`App\Services\AdBudget`) — the advertising budget/CPC configuration screen deliberately deferred from Phase 8
  (see [ADAPTIVE-PRICING.md](ADAPTIVE-PRICING.md) §9): a planned daily/monthly spend cap, and a forecast of what a planned spend is
  expected to buy, projected from real recent unit economics.

Both are planning information only. Neither ever blocks, changes, or re-prices anything that actually happens — the same discipline as the
Price Experiment Simulator (`App\Services\Pricing::simulate`), which projects at real volume without touching a live price.

## 1. Sales Target

### 1.1 The one rule that matters

**`App\Services\Analytics`'s own rollup is the only source of "actual revenue so far."** `progress()` calls `Analytics::rollup()` /
`Analytics::sum()` — the exact same code path the dashboard uses — so a pacing figure here can never disagree with what Dashboard shows for
the same days. Nothing here computes revenue independently.

### 1.2 Storage (`sales_targets`, migration `014`)

Append-only, audited, like every other settings-history table in this application: `period_start`, `period_end`, `target_paise`, `note`,
`set_by`, `created_at`, `revoked_at`. Setting a new target that **overlaps** an existing, not-yet-revoked target's period revokes the old
one (never deletes it) — `current()` (the target whose period covers today) always has an unambiguous answer. A target can be ended early
with `revoke()`, independent of overlap.

### 1.3 Validation (`SalesTarget::set`)

* `period_start`/`period_end` must be real calendar days; the end must be on or after the start.
* A target can cover **at most 366 days**.
* The target itself must be **whole or fractional rupees above ₹0**, up to **₹1,00,00,000** (a generous typo guard, not a real business
  cap — `SalesTarget::MAX_TARGET_PAISE`).
* An optional note, at most 255 characters.

### 1.4 Pacing math (`SalesTarget::progress`), exactly as computed

| Period state | `status` | What is shown |
|---|---|---|
| Today is before `period_start` | `not_started` | The target amount and the average daily pace that *would* be needed across the whole period (`target ÷ total_days`) — no actual, no projection. |
| Today is within the period | `in_progress` | **Actual** revenue from `period_start` through today (inclusive); **% of target**; **daily pace** (actual ÷ elapsed days); a linear **projection** to the period's end (daily pace × total days) and its % of target; the **pace label** (below); the exact **daily pace still needed** on the remaining days to reach the target (`0` once the target is already met on paper). |
| Today is after `period_end` | `ended` | The period's **final** actual revenue and % of target — `projected` simply equals `actual` (nothing is projected once there is nothing left to project); no "required pace" (there are no days left). |

**Pace label** (from the *projected* % of target, in tenths of a percent, half-up-rounded elsewhere in the app the same way):
`ahead` (≥ 105.0%), `on_track` (95.0–104.9%), `behind` (< 95.0%). This is a **simple linear hold-the-pace-constant projection**, explicitly
not a demand forecast — exactly the same honesty rule the Price Experiment Simulator and the Ad Budget forecast (below) both follow.

### 1.5 Admin UI (**Admin → Advertising**, `ads.view` / `ads.manage`)

A card shows the current target's period, amount, actual-so-far with a progress bar, projected finish and the pace still needed, plus
**Set a new target** (also used to edit — saving a new target simply supersedes the old one, per §1.2) and **End this target early**
(`revoke()`, confirmed). With no target set, only the prompt to set one is shown.

## 2. Ad Budget & Forecast

### 2.1 Storage (`ad_campaign_settings`, already provisioned by migration `009` but unused until now)

`kind` = `default` (the standing budget) or `override` (a `period_start`..`period_end`-scoped budget, e.g. a festival sale).
`settings_json` = `{daily_budget_paise, monthly_budget_paise}` — either may be `null`, meaning **no cap** (today's behaviour, unchanged, is
the default state of a fresh install). Append-only and audited exactly like Sales Target: saving a new `default` row supersedes the
previous `default` row; saving a new `override` supersedes only the **overlapping** `override` row(s) — the standing `default` is never
touched by an override's save or its early end (`revokeOverride()`).

`AdBudget::current()` resolves **today's** effective budget: an active override (if any) wins over the default; with neither, the budget
is "no cap," identical to every installation before this release.

### 2.2 Validation (`AdBudget::save`)

* `scope` is `default` or `override`; `override` additionally requires a valid `period_start`/`period_end` (end ≥ start, at most 366 days).
* `daily_budget`/`monthly_budget` are each optional — **blank means no cap**, not zero (an explicit `0` is refused: "enter an amount, or
  leave it blank"). Caps: ₹10,00,000/day, ₹1,00,00,000/month (`AdBudget::MAX_DAILY_PAISE` / `MAX_MONTHLY_PAISE`).
* An optional note, at most 255 characters.
* **A budget here is informational only.** Nothing in checkout, the tracker, or `Ads::update()` reads it; it never blocks a payment or
  throttles a visitor. The admin UI says so plainly next to the fields.

`reset()` is `save(['scope' => 'default', 'daily_budget' => null, 'monthly_budget' => null], …)` — an explicit, audited "no cap," not a
silent revert.

### 2.3 The forecast (`AdBudget::forecast`)

Given a planned spend (and, optionally, one campaign), projects what it is expected to buy from the **last 30 days' real** unit economics
— never a demand forecast, exactly like §1.4's pacing projection:

1. **Cost per qualified visitor** — the real blended figure from `App\Services\AdsIntel::overview()` (actual spend ÷ qualified visitors,
   GST per the existing `finance.ad_gst` setting — the same number Ads Intelligence itself shows) when any actual spend has been entered
   in the window; otherwise it falls back to the configured **CPC** (`Ads::cpcPaise()`), the same real/estimate-fallback rule
   `Ads::estimatedPerOrder()` already uses elsewhere. The response says plainly which one was used (`basis.cost_per_qualified_is_real`).
2. **Qualified visitors** = `floor(budget ÷ cost per qualified visitor)`.
3. **Conversion rate** and **revenue/contribution per customer** come from the same 30-day window's real attributed orders
   (`AdsIntel`'s own `conversion_tenths`, `attributed_revenue`, `attributed_costs`). **With zero customers in the window, nothing is
   guessed** — `customers`, `revenue_paise` and `contribution_paise` are all `null`, and the admin UI says plainly that there isn't enough
   data yet, rather than inventing a conversion rate.
4. **Customers** = `floor(qualified visitors × conversion rate)`; **revenue** = `customers × revenue/customer`; **contribution** =
   `(customers × contribution/customer) − the planned budget itself` (the budget is the actual planned ad cost, never scaled from
   history).
5. A **campaign** name (optional) scopes every one of the above to that campaign's own real numbers only (`AdsIntel::overview(['campaign'
   => …])`) — campaign-level forecasting, never a blended shop-wide rate leaking into a specific campaign's projection, or vice versa.

No write happens; `forecast()` is a pure read, gated by `ads.view` like every other read-only Ads screen, called from the admin UI with a
single **Forecast** button (not live-as-you-type, to avoid hammering the endpoint).

### 2.4 Admin UI

A card shows the budget in force today (source: `default`/`override`/`none`, the daily/monthly amounts, the override's date range if
any), **Change budget**, **End this override early** (only shown while one is active), and **Remove all caps** (`reset()`, only shown
while a cap exists). A separate **Budget forecast** card takes a planned spend and an optional campaign and shows the projection above,
with its basis spelled out (the window, the real-or-estimated cost per qualified visitor, the conversion rate and the sample size it came
from).

## 3. Permissions

Both features reuse the existing **`ads.view`** (read: current state, history, the forecast) / **`ads.manage`** (write: set/revoke a
target, save/reset/revoke-override a budget) permissions — the same pair that already gates the CPC settings on this page. No new
permission was introduced.

## 4. Tests

`tests/Integration/SalesTargetTest.php` (11 tests) — persistence and audit, every validation rule, overlap-supersede (and that a
non-overlapping target is left alone), `revoke()` and its 409 with nothing current, `history()`, and the pacing math for all three period
states (`not_started` / `in_progress` / `ended`), including a direct cross-check against `Analytics::rollup()`/`sum()` for the same days.

`tests/Integration/AdBudgetTest.php` (13 tests) — persistence, audit, default-supersedes-default and override-supersedes-only-overlapping-
overrides (the standing default is left alone either way), override-beats-default while active, every validation rule, `reset()`,
`revokeOverride()` (plus its 409), `history()`, and the forecast: the CPC fallback with zero data (customers explicitly `null`, never
guessed), real spend/conversion data winning over the fallback with the exact expected numbers, campaign-scoped isolation (one campaign's
rate never leaking into another's forecast), and the negative-budget rejection.

Both suites use the same synthetic-order fixture pattern as `tests/Integration/AnalyticsTest.php` (`Clock::setFixed`, direct inserts, no
HTTP round trip) for fast, deterministic day-by-day control.
