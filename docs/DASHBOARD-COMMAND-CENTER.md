# Dashboard command center

Priority 4 of the master prompt: the admin dashboard as the one screen that shows the whole business's state, and two new automated
Business Watchdog checks that turn Priority 3's planning tools (Sales Target, Ad Budget) into proactive notifications.

## 1. The "Business health" row

A new row on **Admin → Dashboard**, right after the KPI cards and before the profit/revenue trends — three cards, each reading straight
from its own service (`App\Services\Dashboard::commandCenter()`), **never a separate computation**:

| Card | Reads | Shows |
|---|---|---|
| **Sales target** | `App\Services\SalesTarget::progress()` | The current target's period and amount, actual revenue so far with a progress bar, and — while it is running — the projected finish and the daily pace still needed. A chip names the state: *Not started*, *Ahead*, *On track*, *Behind*. With no target set, a prompt links to Admin → Advertising. |
| **Advertising budget** | `App\Services\AdBudget::spendSoFar()` | Today's and this calendar month's actual ad spend (the same `ads_spend_paise` figure — actual beats estimate — the dashboard's own KPIs and Finance use) against the configured daily/monthly cap, in red once over. A chip names the state: *No cap set*, *Within budget*, *Over budget*. |
| **Adaptive pricing** | `App\Services\Pricing::profitGuard()` | How many experiments are currently running, how many are at risk or have breached their profit floor, and how many rolled back recently. A chip names the state: *Idle*, *Paused*, *Running*, *At risk*, *Profit floor breached*. |

Each card links to the screen where the admin acts on it (Advertising, Advertising, Adaptive pricing).

### Role visibility — removed, not merely hidden

Same discipline as `Dashboard::withoutFinance()` (which already strips every profit/cost/ads figure for a role without `finance.view`):

* **`Dashboard::withoutAds($d)`** removes `command_center.sales_target` and `command_center.ad_budget` — called by
  `DashboardController` for any role without `ads.view` (today: `support`).
* **`Dashboard::withoutPricing($d)`** removes `command_center.pricing` — called for any role without `pricing.view` (today: `support`).

A card whose data was stripped is not rendered at all; the admin never sees an empty or placeholder card, and the row itself disappears
if every card is stripped.

## 2. Two new automated Watchdog checks

Added to `App\Services\Watchdog::CHECKS` (see [WATCHDOG-AND-REPORTS.md](WATCHDOG-AND-REPORTS.md) §1 for the full table and the
verdict vocabulary) — run by the existing `watchdog` job every 15 minutes, **notifications only**: neither check ever spends money,
changes a price, or approves/refunds an order. "Business automation jobs" here means what the Business Watchdog has always meant —
an automated, recurring check that raises or resolves an alert on its own — extended to the two new planning tools from Priority 3,
not a new kind of job or a new automated-action system.

* **`sales_target`** — the current sales target's own `pace_label` (`App\Services\SalesTarget::progress()`). "Not enough data" with no
  target set, or one that has not started yet. Alerts (warning) when the pace is `behind` (projected finish under 95% of target — the
  exact cutoff the Sales Target card itself uses, never re-thresholded here); escalates to **critical** once the projected finish falls
  below the configurable `target_critical_pct` (default 75%).
* **`ad_budget`** — today's and this month's actual spend vs the configured cap (`App\Services\AdBudget::spendSoFar()`). "Not enough
  data" with no cap set at all. Alerts (warning) when either the daily or the monthly cap is exceeded, naming which; escalates to
  **critical** once spend reaches the configurable `budget_critical_pct` (default 150% of the cap).

Both follow every existing Watchdog convention without exception: one alert per problem (occurrences count up on repeat detections,
never a duplicate row), resolved automatically only after `clean_runs` consecutive clean runs (default 2 — never on a single "not
enough data" result, which is explicitly **not** treated as healthy), thresholds changeable in **Admin → Business alerts →
Thresholds** with the same validation/audit/bounds as the other 12 checks, and an entry in the **Checks** tab's generic, data-driven
list (nothing about the Checks tab, the Thresholds tab, or the e-mail alerting path needed changing — both new checks slot into the
same `CHECKS`/`FIELDS`/`DEFAULT_OFF` consts every other screen already reads generically).

## 3. Tests

`tests/Integration/WatchdogTest.php` — 6 new tests: no target / not-started is "insufficient" for `sales_target`; comfortably ahead is
healthy with no alert; badly behind alerts and escalates to critical past the threshold; an alert resolves once pace recovers (through
the real job, not a direct call); the equivalent three for `ad_budget` (no cap is "insufficient", under budget is healthy, over either
cap alerts and escalates, resolves once spend is back within the cap).

`tests/Integration/AnalyticsTest.php` — 1 new test: the command center's three cards read exactly what their own services compute (the
dashboard's own order feeds the same `Analytics` rollup `SalesTarget::progress()` reads — never a second, possibly-disagreeing
computation), and `withoutAds()`/`withoutPricing()` strip independently of each other and of `withoutFinance()`.

Three pre-existing tests that hardcoded the watchdog's check/threshold counts (`tests/Integration/AlertsAccessTest.php` and
`tools/qa/admin-alerts.mjs`) were updated from 12 checks / 30 thresholds to 14 / 32 — the expected, intentional consequence of adding
two checks and two thresholds, not a regression.

Browser QA: `admin-dashboard.mjs` and `admin-alerts.mjs` rerun clean (including role-permission matrices and the mobile layout sweep);
manually verified in the browser with a behind-pace target and an over-budget cap, on desktop and mobile, with zero console errors.
