# Ads Intelligence

What advertising cost, who came, who paid, and what the paying visitors were worth — per campaign. Everything here is real code with tests
(see [§10](#10-tests-that-were-run-and-what-was-not)); nothing is simulated, and the screen never claims more than the data can say.

> **Three rules this feature is built around**
> 1. **Actual or estimate, never both.** Advertising cost is the *actual* spend an admin entered for a day, or — for a day with none — the
>    *CPC estimate* (qualified visitors × CPC). A day with any actual spend carries no estimate. Every figure says which one it is.
> 2. **A visit is not a person, and a view is not a visitor.** Visits (sessions), page views, visitors (per day), qualified and engaged
>    visitors, checkout starts and verified customers are different numbers with different units; the screen says which unit each uses.
> 3. **Attributed revenue is credit, not cause.** An order is credited to a campaign by a stated model (last non-direct touch inside a
>    window). No test of what would have happened without the advertising is run, so the screen never calls it *incremental revenue*.

All money is integer paise; days are business-time-zone days (default `Asia/Kolkata`).

## 1. What the visitor tracker records

Privacy design is unchanged (first-party cookie, **no IP address, no fingerprint, no raw user-agent** stored; bots are ignored). On top of it:

| Measured | Definition | How reliable |
|---|---|---|
| **Visitor** (per day) | one row per person-cookie per business day (`visitor_days`) — the dashboard's unit | a cleared cookie is a new visitor |
| **Qualified visitor** | stayed the qualifying time (3 s by default) of *visible* time; **verified by the server**, which refuses an early or replayed report; one per visitor per day; the unit the CPC estimate is billed on | unchanged rule |
| **Session / visit** | a visit: a refresh stays in it; a new one starts after 30 minutes idle **or on a click from a different campaign** | not a count of people |
| **Page views** | page loads inside sessions (a refresh counts) | counted from the update that added it; older sessions show 0 and the screen says so |
| **Engaged session** | qualified **and** opened a service card | the card is reported by the browser (a count, never which one); it **never affects spend** |
| **Returning session** | the visitor's cookie had an earlier visit; a campaign-triggered second session of the *same visit* inherits the first one's value | a lower bound: a cleared cookie looks new |
| **Campaign tags** | `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` | see below |
| **Checkout start** | a visitor-day on which an order was created | — |
| **Verified customer** | a distinct visitor with a **verified** payment (an order from a visitor the shop could not track counts as one customer) | — |

**Campaign tags are cleaned before they are stored:** lower-cased, only `a–z 0–9 _ . -` and space, at most 80 characters; markup, quotes, `;`,
`:`, `/`, control characters and non-ASCII are dropped; an empty result is "no campaign". A click on a **different** campaign starts a
**new session**, so the earlier tag cannot keep the credit for a later click; the same tag again, or a page without a tag, stays in the
session. A visitor-day is named after the **first campaign-tagged session of that day** (`visitor_days.utm_campaign`), once — which is what
lets visitors, qualified visitors and the estimated spend split by campaign add up exactly to the dashboard.

## 2. How a verified order is credited

* **Model `last_non_direct`, window 7 days** (setting, 1–90): of the visitor's sessions that **started inside the window before the payment was verified**, the
  most recent one that arrived through a **campaign tag**, a **source tag** or a **referring site** gets the credit; none → **direct**.
* It is decided **once, on the server, at verification** (inside the settlement code, after the payment commits), from session rows the server wrote earlier,
  and stored with the **model, the window used and the time of the touch** (`campaign_attribution`, one row per order, `INSERT IGNORE` on a unique key).
  **Nothing the browser sends afterwards, and no later change of the window, rewrites it.**
* A row missed because the process died after the payment committed — or an order paid before attribution existed — is filled in later from the same
  server-side data by the *analytics* job, or by **Settings → Attribute them…** (dry run first, idempotent, audited). The window in force is stored on
  each row, so a reader can tell what credited it.
* Only a **verified** payment is attributed; a pending, failed or expired one never is.

## 3. Spend

* **Actual** (`ad_daily_spend`): typed in or pasted from the ad platform's report, per day / campaign / platform: pre-tax amount, GST (kept apart), the clicks and
  impressions the platform reported (empty = unknown). `0` is a statement ("nothing was spent"), not a missing entry. The same day + campaign + platform
  **replaces** the earlier entry (the old value is in the audit log); an entry is **voided with a reason**, never deleted.
* **Estimate**: qualified visitors × the CPC in force when each visitor qualified (`visitor_days.cpc_paise`) — on days with **no** actual spend. Not billed spend.
* **Per day, never both.** A day with any actual entry carries no estimate for anyone — so enter every campaign of a day, or the day's total. Spend entered
  without a campaign is shown as **"(not split by campaign)"**: real, but with no visitors of its own to be credited to.
* GST on actual spend is a cost or recoverable per the setting under Finance → Settings; Finance and Ads Intelligence use the same rule and the same numbers.
* **CSV import** (≤ 500 lines, comma / semicolon / tab, header optional): `date, campaign, platform, spend, gst, clicks, impressions`. All or nothing — one bad
  line rejects the whole paste and names the line; the page shows a **check** (how many new, how many replacing) before anything is saved.

## 4. The figures and their units

| Figure | Unit and definition |
|---|---|
| Spend (today / lifetime / period) | actual where entered, estimate elsewhere; labelled **actual spend**, **CPC estimate**, **actual + estimate** or **no spend** |
| Visitors, qualified visitors, checkout starts | **visitor-days** — the dashboard's numbers; campaign rows add up to the cards |
| Sessions (visits), page views, engaged, returning | **sessions**, by the session's own campaign tag |
| Verified customers, orders, attributed revenue | **verified payments**, by the day verified, credited by the model; revenue is **net of refunds** |
| Conversion rate | verified customers ÷ qualified visitors (same campaign and period). It can pass 100 % when the buyers first visited before the period |
| CPC | pre-tax actual spend ÷ the clicks the platform reported, only for entries that carry clicks; empty otherwise |
| Cost per qualified visitor / per customer | advertising cost ÷ qualified visitors / verified customers |
| Contribution after ads | attributed revenue − the provider, fulfilment, fee and other costs of those orders − the campaign's advertising cost |

A customer who bought through two campaigns is one customer in the total and appears in both campaign rows (the table says so). With every order attributed, the
shop-wide *contribution after ads* equals Finance's *contribution − advertising* (a test asserts it).

**Confidence** (shown per campaign and overall, with the reason): **high** — actual spend only and ≥ 30 attributed orders; **medium** — actual spend and ≥ 10
attributed orders (the label says if part of the spend is an estimate); **low** — spend is an estimate or missing, or too few orders. A low-confidence ratio is shown,
labelled — not hidden, and not dressed up.

**Missing data is named, never guessed** (the notes on the Overview): verified orders with no attribution row (with the way to fix it), orders from visitors the shop
could not track, sessions recorded before page views were measured, no clicks → no CPC, no qualified visitors → no conversion rate (not 0 %).

## 5. Screens

**Admin → Overview → Ads Intelligence** (`ads.view`: owner, manager, analyst; support does not see the page, the menu entry or the data):

* **Overview** — twelve cards: spend today / lifetime / in the period (with the source label), visits, qualified visitors, checkout starts, verified customers,
  conversion rate, cost per customer, attributed revenue, contribution after ads, and source & confidence; the notes; two trend charts (spend vs attributed
  revenue; qualified visitors vs verified orders); the definition of every figure. Filters: range (*Today · 7 · 30 · 90 days · Lifetime · Custom*) and campaign —
  kept in the URL.
* **Campaigns** — one row per campaign (spend + source, qualified, checkouts, customers, conversion, cost per customer, attributed revenue, contribution after ads +
  confidence); *Details* opens that campaign on the Overview. "(no campaign)" is untagged traffic.
* **Spend** (`ads.manage` to change) — the entries, filter by campaign, show voided, **Add spend**, **Import CSV**, **Void…**.
* **Settings** — the attribution window (`ads.manage`), the orders without attribution and **Attribute them…**, and where the CPC and GST settings live.

## 6. API

All under `/admin/api`, JSON, CSRF-protected, idempotent where they write (`Idempotency-Key`), audited. `403 forbidden` without the permission.

| Route | Permission | |
|---|---|---|
| `GET /ads?range=&from=&to=&campaign=` | ads.view | `cards`, `campaigns`, `trend`, `coverage`, `notes`, `definitions`, `meta`, `can {manage}`; `422` for a campaign with no data |
| `GET /ads/spend?from=&to=&campaign=&include_voided=&page=` | ads.view | entries (25 per page), `sum_paise`, `gst_paise`, `campaigns` |
| `POST /ads/spend` `{day, campaign?, platform?, amount, gst?, clicks?, impressions?, note?}` | ads.manage | enter or replace one entry (`422` with `fields`) |
| `POST /ads/spend/{id}/void` `{reason}` | ads.manage | void with a reason (`409` when already voided) |
| `POST /ads/spend/import` `{csv, dry_run?}` | ads.manage | check or import a pasted report, all or nothing |
| `GET` / `PUT /ads/settings` `{window_days}` | ads.view / ads.manage | the attribution window (1–90; `422` with the field) |
| `POST /ads/attribution/backfill` `{dry_run?}` | ads.manage | attribute verified orders that have no row yet |

The tracker's beacons gained fields only: `POST /api/track/hit` also accepts `utm_content` and `utm_term`; `POST /api/track/ping` accepts `services` (a count, 0–200).

## 7. Database

Migration `008_ads_intelligence` (Phase 2) created `visitor_sessions.utm_content / utm_term / page_views / service_views / engaged_at / returning_visitor`,
`ad_daily_spend` and `campaign_attribution`. Migration `012_visitor_day_campaign` adds `visitor_days.utm_campaign` (+ an index) and fills it, best effort, from the
sessions that still exist (the first tagged session of the same visitor on the same day; older rows stay "no campaign" — history is not invented). It only adds a
column; rollback notes are at the top of the file.

## 8. Cron

Nothing new to schedule. The existing `analytics` job (`php bin/cron.php analytics`, every five minutes or via the one-minute `php bin/cron.php`) now also
attributes verified orders from the last 14 days that have no attribution row; `--dry-run` writes nothing.

## 9. Demo data

`tools/demo-data.php` (the local demo and the QA baseline) now creates sessions, three campaigns (`diwali-reels`, `yt-shorts-push`, `creator-collab`), credited orders
and **actual** ad spend for most of the last 24 days (today's bill is "not in yet"; older days stay on the CPC estimate) so the screens show both kinds. It is
synthetic data and says nothing about a real business.

## 10. Tests that were run, and what was not

**Run (MariaDB 10.11, PHP 8.3, Chromium):**

* PHP suite — **297 passed, 0 failed, 4,584 assertions**. New: `AdsTest.php` (13: the five tags and their cleaning, hostile values, a different campaign starts a new
  session, the visitor-day named once, the 3-second rule and the single daily charge unchanged, engaged = qualified + a card whichever comes second and bounded;
  attribution — last non-direct touch inside the window, stored once and never rewritten, source-only and untracked orders, the window validated and audited, the
  backfill and the job; the overview — campaign rows add up to the dashboard's visitors, qualified visitors, checkout starts **and advertising cost to the paisa**,
  actual spend replaces the estimate per day and follows the GST setting, refund-adjusted revenue and costs equal Finance's contribution − advertising, missing data
  named, confidence; migration `012`'s backfill) and `AdsAccessTest.php` (2: every endpoint by role, a refused call changes nothing and writes no audit row,
  idempotent void, validation as field errors, paging and filters).
* Browser suite `admin-ads` — **12/12**: every card and every campaign row compared with **independent SQL** over the raw rows (period, today, lifetime; the rows add up
  to the cards and to the dashboard's totals), filters and URL state, spend entry (validation in place, the entry replaces its day's estimate, the same key replaces
  the entry), CSV check → import, void with a reason (the estimate returns), the attribution window and *Attribute them…* (dry run first), the role matrix (support /
  analyst / manager), a phone, no console / CSP / network problems.
* `site-tracking` — **9/9** in real browsers: all five tags cleaned and kept, page views, a different campaign starts a new session, opening a service card + the
  qualifying time = engaged, **billing unchanged**, a hostile tag cleaned, a paying campaign visitor credited once at verification and shown on the page, and the
  dashboard still reconciling to the penny with the raw rows (restated for actual spend). `a11y` — **23/23** (axe on every Ads tab and both dialogs, desktop and phone).

**Not done / limits (said plainly):**

* **No live advertising-platform integration:** actual spend is typed or pasted by an admin; there is no API import from Meta, Google or any other platform.
* Attribution is **cookie-based last-touch credit**. It does not see a cleared cookie, a second device or a purchase after the window; it is not a causal measurement.
* *Engaged* depends on the browser reporting a card; *returning* is a lower bound; page views before this update are 0.
* A live Alpha UPI account and a live SMM panel were not used (mock servers only). The MySQL 8 run of migration `012` and of the new tests is part of the final pass.
