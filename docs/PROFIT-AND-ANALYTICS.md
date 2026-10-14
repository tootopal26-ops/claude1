# Profit, advertising cost and analytics

All figures are **integer paise** end to end (`Money` never uses floating point; percentages are integer tenths). One class, `app/Support/Profit.php`, defines the rules once — PHP for single rows and matching SQL fragments for aggregates — and the tests assert that both agree.

## 1. Profit rules

```
Revenue (net)  = amount paid − refunds
MANUAL order   : gross profit = revenue                      (100 % counts as profit before ad cost)
AUTO order     : gross profit = revenue − provider cost
Net profit     = gross profit − advertising cost
Margin         = net profit ÷ revenue                        (one decimal place; "—" when revenue is 0)
```
The dashboard card is literally **`REVENUE − SMM COST − ADS COST = NET PROFIT`** (+ margin).

* **Provider (SMM) cost** is counted **once**, on the order that incurred it. While an order is in progress the cost is the *estimate* (`services.smm_cost_paise` at the time of purchase); when the provider reports its **actual charge** (cron sync) the actual figure replaces the estimate and the order is marked final. A provider-cancelled/failed order costs 0 (the provider refunded us). The provider's currency is converted with the rate you set (`rate_to_inr_micros`).
* **Ad cost** is counted **once**, from qualified visitors — it is never also deducted inside an order's profit. That is why order- and service-level profit is "before ads", and only the dashboard's breakdown (and net profit KPIs) subtract advertising.
* **Refunds** reduce revenue (and therefore profit) from the day of the order's payment; partial refunds are supported. A refunded AUTO order keeps its provider cost (money already spent) unless the provider refunded it.
* **Late payments** count on the day they were received.

### Service profit preview (Admin → Services)
For each service: `price − provider cost (AUTO only) − estimated ad cost per order`.
*Estimated ad cost per order* uses **real data** when available — `qualified-visitor ad spend of the last 30 days ÷ paid orders in the last 30 days` (needs ≥ 10 paid orders) — otherwise `CPC ÷ assumed conversion rate` (default 4 %). The basis is printed under the preview so you always know where the number came from.

## 2. Visitor tracking & CPC

| Concept | Definition |
|---|---|
| **Visitor** | A first-party cookie `smb_v` (random id, 400 days). **No IP address, no fingerprint, no raw user-agent** is stored — only coarse browser / OS / device class, source (Instagram / YouTube / direct / other, from referrer or UTM) and the landing path. Refreshing never creates a new visitor; if cookies are blocked the visitor is simply not counted. |
| **Visitor-day** | One row per visitor per **business day** (`visitor_days`, unique on `(visitor_id, day)`). |
| **Qualified visitor** | Stayed **≥ 3 seconds of *visible* time** (configurable 1–60 s). The browser counts visible time only (a hidden tab does not accumulate) and asks the server to qualify; the server **re-checks the elapsed time** since the page load, so a forged early request is refused. |
| **CPC** | Cost per qualified visitor-day, default **₹1.08** (`cpc_paise = 108`). Charged **once per visitor per day** however often they return, and stored as a **snapshot** in `visitor_days.cpc_paise` at the moment of qualification — changing the CPC later never rewrites history (only *new* visitors pay the new rate). |
| **Ad spend** | `Σ cpc_paise` of qualified visitor-days in the range. |
| **Bots** | Crawlers, headless browsers, `curl`, link-preview fetchers and empty user-agents are never tracked and never cost ad money. |

The CPC and the qualification time are edited in **Admin → Advertising**; every change is stored (who/when) and audited.

## 3. Dashboard (Admin → Dashboard)

### 14 KPI cards — each for **Today** and **Lifetime**
| KPI | Definition |
|---|---|
| Revenue | net revenue of paid orders (paid − refunds), by the day of payment |
| Profit | net profit = revenue − SMM cost − ads spend |
| Orders | orders created |
| Visitors | distinct visitor-days |
| Successful payments | payments confirmed |
| Ads spend | qualified visitors × CPC (snapshots) |
| SMM spend | provider cost of AUTO orders (actual where known, estimate otherwise) |

Each card shows the change against the previous equivalent period (yesterday / the 7 days before).

### Charts, ranges and filters
* Ranges: **Today · 7 days · 30 days · 90 days · Lifetime · Custom** (custom: up to 1,830 days, never into the future). "Today" is the shop's business day, bucketed by hour; ranges up to 120 days by day, up to 800 days by week, longer by month.
* Filters: **platform**, **mode** (AUTO / MANUAL) and **payment** outcome. With any filter active the profit shown is **before ads** (ads are site-wide and cannot honestly be attributed to a platform) and the page says so (`ads_attributable = false`).
* Sections: revenue & profit over time · orders (AUTO vs MANUAL, status mix) · visitor analytics (total, qualified, device, source, funnel visitors → orders → paid, conversion) · top services & performance · SMM spend (by service/provider) · payment success rate (success / failed / expired) · recent orders, payments and activity.

### How the numbers stay fast and exact
* Per-day aggregates live in `analytics_daily`. A day is marked **dirty** whenever an order, payment, refund or visitor row for that day changes; reads recompute dirty days on demand and cron refreshes the last three days — so the dashboard is always consistent with the raw rows but never scans millions of rows.
* Lifetime values are sums of daily rows; today's partial day is computed live.
* **Reconciliation is tested:** `tools/qa/site-tracking.mjs` drives real browsers and asserts that the dashboard's visitors, qualified visitors and ad spend equal the raw `visitor_days` rows to the paisa; the PHP suite asserts SQL and PHP profit formulas agree.

### Time zone
Set in **Admin → Website → Business time zone** (default `Asia/Kolkata`). It decides what "today" means for reports and ad days. Once orders or visitor-days exist it is locked: changing it would re-bucket history and could collide the unique `(visitor_id, day)` keys.
