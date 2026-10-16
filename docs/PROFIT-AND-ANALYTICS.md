# Profit, advertising cost and analytics

All figures are **integer paise** end to end (`Money` never uses floating point; percentages are integer tenths). One class, `app/Support/Profit.php`, defines the rules once — PHP for single rows and matching SQL fragments for aggregates — and the tests assert that both agree.

## 1. Profit rules

> **Changed in the profit-intelligence phase:** a MANUAL order is no longer "100 % profit". Every cost an order has is subtracted exactly
> once, and a cost that is **not known is labelled, never counted as ₹0**. The full definitions, the *actual / estimated / incomplete*
> labels, the fee model, expenses and chargebacks are in [PROFIT-INTELLIGENCE.md](PROFIT-INTELLIGENCE.md); this page keeps the summary.

```
Revenue (net)  = amount paid − refunds
AUTO order     : gross profit (contribution) = revenue − provider cost − the order's cost lines (payment fee, other)
MANUAL order   : gross profit (contribution) = revenue − the order's cost lines (fulfilment, payment fee, other)
Net profit     = contribution − chargebacks − business expenses − advertising
Margin         = net profit ÷ net revenue                    (one decimal place; "—" when net revenue is not above 0)
```
The dashboard card is **`REVENUE − COSTS − ADS COST = NET PROFIT`** (+ margin), where *costs* = the provider cost **plus** fulfilment, payment
fees, other order costs, chargebacks and business expenses; it also says whether any of those costs are incomplete.

* **Provider (SMM) cost** is counted **once**, on the order that incurred it. While an order is in progress the cost is the *estimate* (`services.smm_cost_paise` at the time of purchase); when the provider reports its **actual charge** (cron sync) the actual figure replaces the estimate and the order is marked final. A provider-cancelled/failed order costs 0 (the provider refunded us). The charge is read as ₹ (INR only): a charge the provider declares in another currency is not converted — the ₹ estimate stays and the order is not marked final.
* **Fulfilment, payment fee and other order costs** are append-only cost lines on the order (`order_costs`), snapshotted when the payment is verified (the fee from the fee model, the fulfilment cost from the service's standard cost) or recorded by an admin with a reason. Changing the fee model or a service's cost later never rewrites an order that was already paid.
* **Ad cost** is counted **once**: the **actual** spend entered for a day, or — for a day with none — the CPC estimate from qualified visitors; never both. It is never also deducted inside an order's profit, which is why order- and service-level profit (*contribution*) is "before ads", and only the dashboard's breakdown and the net-profit KPIs subtract advertising.
* **Refunds** reduce revenue (and therefore profit) from the day of the order's payment; partial refunds are supported. A refunded AUTO order keeps its provider cost (money already spent) unless the provider refunded it.
* **Late payments** count on the day they were received.

### Service profit preview (Admin → Services)
For each service: `price − provider cost (AUTO) or fulfilment cost (MANUAL, when stated) − estimated ad cost per order`. A MANUAL service whose fulfilment cost is **not stated** says so instead of inventing a number (its orders are labelled incomplete in the reports).
*Estimated ad cost per order* uses **real data** when available — `qualified-visitor ad spend of the last 30 days ÷ paid orders in the last 30 days` (needs ≥ 10 paid orders) — otherwise `CPC ÷ assumed conversion rate` (default 4 %). The basis is printed under the preview so you always know where the number came from.

## 2. Visitor tracking & CPC

| Concept | Definition |
|---|---|
| **Visitor** | A first-party cookie `smb_v` (random id, 400 days). **No IP address, no fingerprint, no raw user-agent** is stored — only coarse browser / OS / device class, source (Instagram / YouTube / direct / other, from referrer or UTM) and the landing path. Refreshing never creates a new visitor; if cookies are blocked the visitor is simply not counted. |
| **Visitor-day** | One row per visitor per **business day** (`visitor_days`, unique on `(visitor_id, day)`). |
| **Qualified visitor** | Stayed **≥ 3 seconds of *visible* time** (configurable 1–60 s). The browser counts visible time only (a hidden tab does not accumulate) and asks the server to qualify; the server **re-checks the elapsed time** since the page load, so a forged early request is refused. |
| **CPC** | Cost per qualified visitor-day, default **₹1.08** (`cpc_paise = 108`). Charged **once per visitor per day** however often they return, and stored as a **snapshot** in `visitor_days.cpc_paise` at the moment of qualification — changing the CPC later never rewrites history (only *new* visitors pay the new rate). |
| **Ad spend** | For a day with **actual** spend entered (Ads Intelligence → Spend): that spend. For every other day: `Σ cpc_paise` of its qualified visitor-days (the **estimate**). Never both for the same day. |
| **Bots** | Crawlers, headless browsers, `curl`, link-preview fetchers and empty user-agents are never tracked and never cost ad money. |

The CPC and the qualification time are edited in **Admin → Advertising**; every change is stored (who/when) and audited.

The tracker also records the five campaign tags (`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, cleaned), page views, engaged and returning sessions, and names each visitor-day after its first campaign-tagged session. What they mean, how a verified order is credited to a campaign, and the per-campaign figures are in [ADS-INTELLIGENCE.md](ADS-INTELLIGENCE.md).

## 3. Dashboard (Admin → Dashboard)

### 14 KPI cards — each for **Today** and **Lifetime**
| KPI | Definition |
|---|---|
| Revenue | net revenue of paid orders (paid − refunds), by the day of payment |
| Profit | net profit = revenue − all costs (provider, fulfilment, fees, other, chargebacks, expenses) − ads spend |
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
