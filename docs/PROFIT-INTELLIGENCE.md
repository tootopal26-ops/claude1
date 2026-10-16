# Profit intelligence

What the shop really earned and what it cost — with every number labelled by how complete it is. Everything here is real code with
tests (see [§10](#10-tests-that-were-run-and-what-was-not)); nothing is simulated, and nothing is estimated silently.

> **The rule this feature is built around: an unknown cost is not ₹0.** A MANUAL order costs something to deliver, the gateway
> charges a fee, an AUTO order's provider charge may not have been reported yet. When a cost is not known, the order is labelled
> **incomplete**, the figure is shown **with that label**, and the *Reconciliation* tab lists what is missing. The old rule
> "a manual order is 100 % profit" is gone.

All money is integer paise. "Today" and every day are in the business time zone (default `Asia/Kolkata`).

## 1. The definitions

One place defines them (`App\Services\Finance::DEFINITIONS`); the Finance page shows each next to its figure.

| Figure | Definition |
|---|---|
| **Verified revenue** (`collected`) | The amount of every order whose payment was **verified**, on the day it was verified. Pending, failed and expired payments are not revenue. A payment that arrives late counts on the day the money was confirmed. |
| **Refunds** | What an admin recorded as returned (the gateway has no refund API), taken off the day the order was paid. |
| **Chargebacks** | Money the bank took back — recorded as an expense of category *chargeback* (it must name its order) on the day it happened. |
| **Net revenue** | Collected − refunds − chargebacks. (The statement also shows *revenue after refunds*.) |
| **API (provider) cost** | What the SMM panel charges for an AUTO order — an estimate from the service until the panel reports its final charge. |
| **Manual fulfilment** | What delivering a MANUAL order costs directly (staff time, a supplier …). |
| **Payment fees** | The gateway's fee for each payment, from the **fee model** (§3). Alpha UPI reports none. |
| **Other order costs** | Any other direct cost of one order. |
| **Contribution** | Collected − refunds − provider cost − fulfilment − payment fees − other order costs: what the orders earned **before** advertising, chargebacks and overheads. |
| **Advertising** | The **actual** spend entered for a day that has any; otherwise the CPC estimate for that day (qualified visitors × CPC). **Never both.** GST on actual spend is a cost or recoverable, per a setting. |
| **Business expenses** | Tools, hosting and other expenses, on the day recorded. Voided entries are not counted. |
| **Net profit** | Contribution − chargebacks − expenses − advertising. |
| **Margin** | Net profit ÷ net revenue — shown only when net revenue is above zero (otherwise "—", never 0 %). |

Each cost is subtracted **exactly once**: the provider cost on the order that incurred it, a payment fee as a per-order line (so
`payment_fee` is not an expense category and cannot be entered twice), advertising once per day.

## 2. How complete is a number (the cost status)

Every paid order has a status, computed by one rule that exists twice — as a PHP function and as the SQL fragment every total uses —
and a test asserts the two agree on every kind of order.

| Status | Meaning | Examples |
|---|---|---|
| **actual** | every applicable cost is a recorded actual | the panel's final charge; an admin-recorded line; "no fee" under the fee model |
| **estimated** | every applicable cost is known, and at least one is an estimate | a service's standard cost; the fee model; a provider charge that is not final yet |
| **incomplete** | an applicable cost is **not** known | an AUTO order with no provider cost; a MANUAL order with no fulfilment cost; a fee model that is not set up |

A figure carries the worst status of the orders it adds up and of its advertising days. The Finance page shows it as a banner that
lists the reasons ("57 of 362 paid orders have a cost that is not known yet", "Advertising is the CPC estimate …"), the Profit
statement says *estimated* / *actual* next to Advertising, and the Orders drawer shows it per order. An AUTO order that never reached
the provider (failed, cancelled, refunded, expired before sending) has no provider cost to wait for.

## 3. Where each cost comes from

**Provider cost (AUTO).** `orders.provider_cost_paise` — the service's cost when the order is placed, replaced by the panel's own
charge when the sync reports it (then `provider_cost_final`). Unchanged from before; only the label is new.

**Fulfilment cost (MANUAL).** Set the *Fulfilment cost per purchase* in **Services → edit** (a MANUAL service shows the field):

* **empty** = *not stated yet* → orders are **incomplete**, never free;
* **0** = *stated: it costs nothing directly* → the order is complete at ₹0 (still an estimate, from the service);
* an amount → each order **snapshots** it **when its payment is verified** (inside the settlement transaction, so an order is never
  "paid" without its cost lines). Changing the service's cost later never rewrites an order that was already paid; moving a paid AUTO
  order to the manual queue gives it the service's standard cost at that moment.

**Payment fee.** Fee model under *Finance → Settings*: `unset` (default — the fee is **unknown**, orders are incomplete), `none` (a
known zero), `percent` (0–20 %, two decimals), `fixed` (up to ₹1,000) or `percent_plus_fixed`. The fee of a payment is **estimated from
the model when the payment is verified** and kept as a line; changing the model never rewrites an old order. *Reconciliation* can add
estimated lines to older orders (§6). There is no way to read the real fee from Alpha UPI — it reports none — so these lines are
always labelled *estimate* unless an admin records the real amount on the order.

**Order cost lines are append-only** (`order_costs`). A *fulfilment* cost and a *payment fee* have one **live** line per order (a unique
`active_key` makes two live lines impossible); recording a correction **ends** the old line (`superseded_at`) and adds the new one.
*Other* costs add lines, and only an *other* line can be removed (with a reason; it stays in the history). Recording needs
`finance.adjust`, a **reason of at least 3 characters**, a paid order, an amount of at most ₹1,00,000; it is audited
(`finance.cost_recorded` / `finance.cost_removed`) and written to the **order's timeline**.

**Expenses and chargebacks** (`expenses`). Category `tools`, `hosting`, `chargeback`, `other`; a date that is not in the future; an
amount of ₹0.01 – ₹10,00,000; *other* needs a description; a *chargeback* needs the order it is for. An entry is **never deleted** —
a wrong one is **voided with a reason** and stays visible (marked void) and in the audit log. Day totals are recomputed when an
entry changes.

**Advertising.** `ad_daily_spend` holds the **actual** spend per day / campaign / platform (entered or pasted from a report); a day that
has any actual spend uses it **instead of** the CPC estimate. The GST setting decides whether the GST part of that spend is a cost
(default) or recoverable (not counted). Entering spend, and everything about campaigns, is in [ADS-INTELLIGENCE.md](ADS-INTELLIGENCE.md) (Admin → Ads Intelligence → Spend).

## 4. The screens

* **Admin → Overview → Finance** (permission `finance.view`):
  * **Profit** — the status banner, four KPI cards, the **Profit statement** (every line above, three columns: *Today*, the chosen
    period, *Lifetime*; on a phone one column at a time), a trend chart, the payments started in the period, and *How these numbers
    are defined*.
  * **Services** — *Most profitable*, *Loss-making*, *Missing cost data* (with the reason for each) and a table of every service that
    sold with collected, refunds, costs, contribution, margin and the status of its costs.
  * **Expenses** — record (`finance.adjust`), filter by category, show voided entries, void with a reason; field errors appear in place.
  * **Reconciliation** — §6.
  * **Settings** — the fee model and the GST treatment of advertising; read-only for roles that cannot adjust.
  * Filters (Profit and Services): range (*Today · 7 days · 30 days · 90 days · Lifetime · Custom*), platform, service, order type,
    payment status — kept in the URL, so a view can be bookmarked.
* **Dashboard** — the profit card is `REVENUE − COSTS − ADS COST = NET PROFIT`, where *costs* are the provider cost **plus** the
  fulfilment, fee, other, chargeback and expense costs; a line under it says what the costs are and whether any are incomplete.
* **Orders → an order** — *Money*: paid, refunded, provider cost, contribution and its status, the **cost lines** with source, who and
  when (earlier amounts kept under *Earlier amounts*), and *Record a cost…*.
* **Services → edit** — the fulfilment field, with a live profit preview that says "not stated, not counted yet" instead of inventing
  a number; the list shows *cost not stated* for a MANUAL service without one.

## 5. A filtered view is honest about what it leaves out

Advertising, chargebacks and business expenses belong to the **whole business**, not to a platform, a service or an order type. With a
platform, service or order-type filter the statement shows those three lines as "—", **net profit equals contribution**, and the page
says so ("Profit before shared costs"). Nothing is attributed by guesswork.

## 6. Reconciliation

*Finance → Reconciliation* lists what does not add up. Each finding has a count, the newest eight orders, and — where one is safe — a
fix. **A fix only ever adds lines**; nothing already recorded is changed.

| Finding | Severity | What it means | Fix |
|---|---|---|---|
| Revenue without a verified payment | high | an order counts as paid but none of its payments was verified | — (see Payment checks) |
| A verified payment whose order is not paid | high | money verified, order not revenue | — |
| Payment fee not recorded | medium | no live fee line (hidden when the fee model is "no fee") | *Add estimated fee lines* — only once a model is set |
| Manual orders without a fulfilment cost | medium | counted at ₹0 and labelled incomplete | *Apply the services' standard cost to N orders* — only where the service states one |
| Provider cost unknown / never reported | medium / low | the order is at the provider but no cost is known / the final charge never arrived | — (sync the order or set the service's cost) |
| Refund status and amount disagree | high | a refunded order with no refunded amount, or a refund above the payment | — |
| Orders that lost money | info | refunds and known costs exceed what was collected | — |
| Advertising is estimated for N of the last 30 days | info | those days use the CPC estimate | enter the actual spend (Ads Intelligence → Spend) |

Every fix has a **dry run first** ("Would add N … totalling ₹X. Nothing was changed."), asks for confirmation, is **idempotent**
(a second run adds nothing — `INSERT IGNORE` on the live key), marks the affected days for recomputation and is audited
(`finance.reconcile_fix`).

## 7. Who may see and change what

| Role | See profit, costs, reconciliation | Record costs, expenses, settings; run fixes |
|---|---|---|
| owner | ✔ | ✔ |
| manager | ✔ | ✔ |
| analyst | ✔ (read-only) | — |
| support | — | — |

The **server** enforces it (`403 forbidden`). For a role without `finance.view` the profit, cost and advertising figures are **removed
from the data** the server sends — the dashboard (breakdown, KPIs, series, cost per click …) and the Orders list and drawer (cost,
profit, cost lines, the provider's own raw answers) — so there is nothing to read in the browser's network tab. The Finance page
and its menu entry are not offered. The Orders drawer then shows only what the customer paid and what was refunded.

## 8. API

All under `/admin/api`, JSON, CSRF-protected, idempotent where they write (`Idempotency-Key`), audited. A role without the permission
gets `403 {error:{code:"forbidden"}}`.

| Route | Permission | |
|---|---|---|
| `GET /finance?range=&from=&to=&platform=&service=&mode=&payment=` | finance.view | the statement (`today`, `period`, `lifetime`), `trend`, `labels` (status + reasons), `payments`, `services`, `definitions`, `meta`, and `can {adjust}` |
| `GET /finance/orders/{id}` | finance.view | one order's money: collected, refunds, provider cost, line costs, contribution, `cost_status`, `reasons`, every line (live and ended) with source and who |
| `POST /finance/orders/{id}/costs` `{type, amount, note, estimate?}` | finance.adjust | record or correct a cost line (`422` with `fields` for a bad amount / note / type, `409 not_paid`) |
| `POST /finance/costs/{id}/void` `{reason}` | finance.adjust | remove an *other* line (`409` for a fulfilment cost or a fee) |
| `GET /finance/expenses?from=&to=&category=&include_voided=&page=` | finance.view | the list with `sum_paise` (voided excluded) and paging |
| `POST /finance/expenses` `{day, category, amount, description?, reference?, order?}` | finance.adjust | record an expense or chargeback |
| `POST /finance/expenses/{id}/void` `{reason}` | finance.adjust | void with a reason |
| `GET /finance/reconciliation` | finance.view | the findings and the open count |
| `POST /finance/reconciliation/fix` `{action: backfill_fees\|backfill_fulfilment, dry_run?}` | finance.adjust | the two append-only fixes (`409` when no fee model is set) |
| `GET` / `PUT /finance/settings` `{fee_mode, fee_percent, fee_fixed, ad_gst}` | finance.view / finance.adjust | the fee model and GST treatment (`422` with `fields`) |

`PUT /services/{id}` also accepts `fulfil_cost` (rupees; empty = not stated; `422 fields.fulfil_cost` for a bad value) and returns
`fulfil_cost_paise` / `fulfil_cost`.

## 9. Database

Migration `005_finance` (Phase 2) created `services.fulfil_cost_paise`, `order_costs` and `expenses`. Migration
`011_finance_rollups` adds to `analytics_daily`: `fulfil_cost_paise`, `payment_fee_paise`, `other_cost_paise`, `chargebacks_paise`,
`expenses_paise`, `ads_actual_paise`, `ads_estimated_paise`, `ads_actual_day`, `orders_cost_estimated`, `orders_cost_incomplete`, and
marks every stored day dirty so the next read recomputes it under the new rules (nothing is lost — the days are derived). It only adds
columns; rollback notes are at the top of the file. Days are recomputed on demand (a day is marked dirty whenever an order, payment,
refund, cost line, expense, ad-spend entry or the fee model changes) and the stored totals are always sums of the same rows the
Finance page shows.

## 10. Tests that were run, and what was not

**Run (MariaDB 10.11, PHP 8.3, Chromium):**

* PHP suite — **282 passed, 0 failed, 4,327 assertions**. New or extended for this phase: `FinanceTest.php` (19: the fee model and its
  limits; snapshots at payment and "a later change never rewrites it"; the cost status in PHP and SQL on every kind of order;
  append-only lines; other costs and void; expenses and chargebacks; the daily numbers with every cost subtracted exactly once;
  actual versus estimated advertising; filters; labels; services; reconciliation and both fixes; the order view; ad-spend validation
  and CSV import), `FinanceAccessTest.php` (3: every endpoint by role, a refused call changes nothing and writes no audit row, no
  profit / cost / advertising key in the dashboard or the orders responses for `support`), `AdminCatalogTest.php` (the fulfilment
  field: empty vs 0, validation, an untouched save keeps it, every change audited), `CoreTest.php` (the service preview).
* Browser suite `admin-finance` — **18/18**: the statement lines in order; **every figure checked against independent SQL** (revenue,
  refunds, provider cost, paid orders — Today, 7 days, custom range, lifetime) and the statement footing on screen; filters; services;
  expenses (validation in place, record, void); the order drawer (record → correct → add → remove; append-only in SQL; audit rows;
  timeline); the service editor; the fee model; reconciliation (counts equal the database, dry run changes nothing, fix adds one line
  per order, second run adds nothing); the dashboard card; the **role matrix** (support / analyst / manager: pages, menu, API reads,
  every write refused with nothing changed); a phone (one statement column at a time, no overflow, nothing covered); no console, CSP or
  network problems.
* Re-run after the changes: `admin-dashboard` 15/15, `admin-catalog` 25/25, `admin-mobile` 10/10 (now also covering Finance, Order
  recovery and Payment checks at 360 / 412 / 768 / 820 px), `security-audit` 16/16, `a11y` 21/21 (axe on every admin page incl.
  every Finance tab and the expense dialog, desktop and phone — it found and I fixed two issues: a region named like its section, and
  a heading level skipped on an empty tab).

**Not done / limits (said plainly):**

* **No real payment fee is read from anywhere.** Alpha UPI reports none. Until a model is set, every order is *incomplete* on that
  count; with a model, the fee is an *estimate* (it is labelled so).
* **No live ad-platform integration:** actual advertising spend is typed or pasted by an admin ([ADS-INTELLIGENCE.md](ADS-INTELLIGENCE.md)); until then it is a CPC *estimate*.
* A live Alpha UPI account and a live SMM panel were not used (mock servers only).
* A fulfilment cost is a number per service purchase that **you** state; the system cannot know what your supplier or your time cost.
* The MySQL 8 run of migration `011` and of the new tests is part of the final integration pass (Phase 9); the SQL uses nothing
  MariaDB-specific.
