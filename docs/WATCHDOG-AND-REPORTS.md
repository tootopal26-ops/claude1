# Business Watchdog and Daily Reports

Two things that watch the shop so a person does not have to: the **Business Watchdog** (Admin → *Business alerts*) looks at the shop's own records every
15 minutes and raises an alert when something crosses a threshold; the **Daily report** (Admin → *Daily reports*) writes down, once per finished
business day, exactly what that day looked like.

**Neither uses an AI model.** Every alert and every report figure comes from fixed rules applied to rows in the database. (Phase 7 adds *optional*
AI commentary on top of a report; it is stored apart from the numbers and can never change them.) Each feature says which technique it uses:

| Technique | Where it is used here |
|---|---|
| Deterministic business rule | 8 of the 12 checks; every report figure; the plain-language "In short" sentences of a report |
| Heuristic anomaly detection (today compared with recent history — it can be wrong) | the checks *Drop in verified sales*, *Rising provider cost*, *Conversion rate change* |
| The provider's documented API | the optional *Provider balance* check (SMM API v2 `balance`), **off by default** |
| A manual admin decision | acknowledging / resolving an alert, changing a threshold, regenerating a report |
| External AI model | none (see Phase 7 for the optional report commentary) |

---

## 1. The checks

Run by the `watchdog` job (`php bin/cron.php watchdog`, every 15 minutes). Each check ends in exactly one **verdict**:

| Verdict | Meaning | Effect on alerts |
|---|---|---|
| **OK** | it ran and found nothing over its threshold | counts as a *quiet run* toward resolving an open alert |
| **Alert** | a threshold was crossed | raises the alert, or updates the one that is already open |
| **Not enough data** | too little data to judge (3 payments in an hour, a shop that is 2 days old …) — **not** "healthy" | none: an open alert stays open |
| **Unavailable** | what it needs is missing or unreadable (no SMM provider, no log folder, a provider that returned no balance) — nothing is assumed | none |
| **Switched off** | disabled under *Thresholds* | none (a live alert it raised earlier stays open for a person) |

| Check | Kind | What it reads | Alerts when (defaults) | Severity |
|---|---|---|---|---|
| **Payment failures** | rule | payments created in the last 60 min, finished ones only (paid, failed, amount mismatch; expired sessions are *not* counted — customers who walked away) | ≥ 40% failed, with at least 5 finished | warning; critical at twice the limit |
| **Payment notifications** | rule | the gateway's webhook deliveries of the last 60 min | ≥ 10 rejected (bad signature) · or ≥ 3 unprocessed / mismatched · or ≥ 5 delivered three or more times | warning; critical when unprocessed ≥ twice the limit |
| **SMM provider calls** | rule | the shop's own `add` / `status` calls, per provider, last 60 min; split into "could not reach / server error" and "rejected" | ≥ 50% failed, with at least 5 calls | warning; critical at ≥ 90%. One alert per provider |
| **Stuck orders** | rule | the order-recovery queue + unsent AUTO orders + old manual orders | an unknown provider outcome, a sending that never finished, an AUTO order unsent after 15 min, a failed/under-review order, a provider order not being followed, a manual order waiting over 24 h | critical if an outcome is unknown or sending is stuck, else warning |
| **Rising provider cost** | heuristic | the provider's **final** charge per 1,000 units, last 3 days vs the 14 days before, per service; estimates are never compared | ≥ 20% rise, at least 3 orders in each period | warning |
| **Missing cost data** | rule | paid orders of the last 30 days with an unknown cost, active services with no cost set, the payment-fee model | any | warning (info if only a service lacks a cost and nothing sold) |
| **Drop in verified sales** | heuristic | the last 24 h vs the daily average of the previous 7 days (fewer if the shop is younger, at least 3) | ≥ 50% below, when the usual level is at least 3 orders a day | warning; critical at ≥ 80% |
| **Conversion rate change** | heuristic | paying visitors ÷ qualified visitors, last 3 days vs the 14 days before | the rate changes by ≥ 50%, with at least 100 qualified visitors in each period | a fall is a warning, a rise is information ("often tracking, not a real improvement") |
| **Scheduled jobs** | rule | the job history | a job failed ≥ 3 times in 6 h, or has not completed for much longer than its schedule | critical if its last 3 runs failed; "overdue" is a warning. One alert per job; the watchdog does not report on itself |
| **Application errors** | rule | `error` lines in the application log (last 60 min) + job runs that failed on the database | ≥ 5 | warning; critical at 4× |
| **Financial discrepancies** | rule | the reconciliation: paid orders without a verified payment, verified payments whose order is not paid, a paid amount that differs from the order, refund status vs refunded amount | any | critical (money records disagree); warning for a refund mismatch only |
| **Provider balance** | provider | OFF by default. The default SMM provider's `balance`, asked at most once an hour, never in a dry run | below the minimum you set (0 = only record it) | warning; critical at ≤ 0 or under half the minimum |

Every threshold above can be changed in **Admin → Business alerts → Thresholds** (30 numeric settings, each with its unit and bounds, plus a switch per
check). A value outside its bounds is refused in place and nothing is saved; every change is written to the audit log with the old and the new value.

### What is deliberately not checked

* **Whether the payment gateway is up.** Alpha UPI documents no status endpoint, so its health is judged from what it *did* — failed payments and bad
  notifications — never from a made-up "gateway status".
* **Whether a provider service is still orderable**, or a provider's balance when the API does not return one. The SMM API v2 has `balance`
  (optional check above) but no availability endpoint other than the 8 MB service catalogue, which is not polled.
* **Anything about customers.** No check sees a customer's name, contact detail or link.

---

## 2. Alerts

Table `business_alerts` (migration 006). One **live alert per problem**:

* `dedupe_key` names the problem (`wd:payment_failures`, `wd:provider_errors:3` for provider 3, `wd:cron_jobs:failed:payments` …). `active_key` holds the
  same value while the alert is **open** or **acknowledged** and is `NULL` once it is **resolved**; a unique index on `active_key` makes "one live alert
  per problem" a database guarantee, even for two cron processes at once.
* Detecting the problem again **updates** that alert (occurrences + 1, last detected, the current figures in the description) — it does not create a row.
  Severity can only go up while an alert is live.
* **Acknowledge** records who has seen it and when; the alert stays live and keeps counting. **Resolve** closes it (resolution `admin`). A person can
  do either only with `alerts.manage` (owner, manager).
* The watchdog resolves an alert **by itself** (resolution `auto`) only after the check has run *clean* the configured number of times in a row (default
  2) — so a flapping condition does not open and close alerts all day, and "no data" never closes one.
* A recurrence after resolution opens a **new** alert; the resolved one stays as history.
* Descriptions are built from numbers and ids and are cleaned before they are stored: no file path, no long opaque string (a key or token), no control
  characters. Nothing technical (stack trace, SQL, table name) is ever shown; the details stay in the server log.

### E-mail (optional, off by default)

Admin → Business alerts → *E-mail*. When on, a **new** alert at or above the chosen severity (critical only, or warning and critical) is e-mailed through
the hosting provider's own mail facility (PHP `mail()`), at most once per alert per cooldown period (5–1440 min, default 60): the right to send is won
with an atomic database update, so two overlapping cron runs cannot both send. The message holds the alert's title and description and a link to the
alerts page — **no credentials and no customer data**. Recipients (up to 5, validated; a line break is not an address, so no header can be injected),
an optional sender and the severity are saved with an audit entry that records the **number** of recipients, not the addresses.

*Send test e-mail* sends one message to the saved recipients (5 per 10 minutes per admin) and reports what actually happened: **handed to the mail
system** or **refused** (`mail()` returned false). It never claims delivery — that is up to the hosting provider's mail service; check the inbox and the
spam folder. If your host does not allow `mail()`, leave e-mail off: the alerts are on the dashboard, the menu badge and the Alerts page.

---

## 3. The daily report

Table `daily_reports` (migration 007): one row per **finished** business day, `UNIQUE(report_date)`. The `report` job writes the report of every finished
day without one (at most the last 7 days back, never before the first day with data, never today). Running it twice, or from two processes, cannot
create a second row.

### The day

Midnight to midnight in the business time zone (**Asia/Kolkata** unless `business.timezone` says otherwise — the report records which). IST midnight is
18:30 UTC the evening before; the report stores the exact UTC window it used. A payment verified at 23:59:59 IST belongs to that day, one at 00:00:01
to the next.

### What is in it

Everything is an integer (paise, counts, tenths of a percent) calculated by the same code as the dashboard and Finance pages (`Analytics`,
`FinanceReport`), straight from the source tables — not from a cache.

* **Sales**: verified revenue, refunds, chargebacks, net revenue, paid orders, average order value.
* **Orders paid that day, by where they are now**: created, completed, still processing, manual waiting for approval, failed / cancelled / refunded /
  expired (partial deliveries are called out, not folded into "failed").
* **Payments**: successful, pending, failed, expired.
* **Costs**: API (provider) costs, manual fulfilment costs, payment fees, other order costs, advertising, expenses.
* **Profit**: contribution before advertising, net profit, margin.
* **Visitors**: visitors, qualified visitors, conversion rate (only with at least 30 qualified visitors — otherwise *unavailable*).
* **The day before**: the eight key figures side by side with the change; no percentage against a zero.
* **Services**: most profitable (top 5), loss-making, and those that **sold on the day with a cost that is not known** (those change the day's profit). Active services that have no cost set but did not sell are only *counted* in one line ("6 other active services have no cost set but did not sell on this day…") — they are a to-do in Finance, not news about the day.
* **API and payment failures**: failed payments, amount mismatches, gateway notifications by outcome, SMM provider calls per provider (failed, split into
  "could not reach / server error" and "rejected"), paid orders that ended FAILED by kind, orders that need a person now.
* **Watchdog alerts** unresolved at the time of building (with how many were first detected / resolved on the day).
* **In short**: a few plain sentences written by fixed rules from the numbers. **How to read this report**: caveats and the definition of each figure.

### Measured, estimated, incomplete, unavailable

Every figure carries a **basis**, shown as a chip next to it and explained in a note:

| Basis | Meaning | Examples |
|---|---|---|
| **Measured** | counted from verified records | verified revenue, paid orders, recorded refunds, actual ad spend, a final provider charge |
| **Estimated** | at least one input is an estimate | a payment fee from the fee model (the gateway reports none), a provider charge the panel has not confirmed (the service's standard cost is used), CPC-based advertising, a manual cost taken from the service default |
| **Incomplete** | an input is *unknown* — the figure is a floor, not the truth | an order whose cost nobody entered (counted as ₹0 and said so) → profit and margin are incomplete |
| **Unavailable** | cannot be calculated | no paid orders → no average order value; fewer than 30 qualified visitors → no conversion rate; zero net revenue → no margin |

Refunds are taken off the day the order was **paid** (not the day the refund was recorded) and order statuses are as of the moment the report was
generated — both stated in the report itself.

### Regenerating

*Regenerate* (permission `reports.generate`: owner, manager) rebuilds the report from today's records **in place** — one row, `regenerated_count` + 1,
`generated_by = admin`. The audit log keeps every figure that changed (before and after, plus the basis), and if any figure changed, AI commentary
(Phase 7) is dropped so commentary never describes numbers that no longer exist. Regenerating with nothing changed says so. A day that is not finished,
is not a date, or is older than the shop's first data is refused with the reason.

---

## 4. Screens and permissions

| Screen | Permission | Who |
|---|---|---|
| Business alerts — read | `alerts.view` | owner, manager, analyst, support |
| Acknowledge / resolve, thresholds, e-mail settings, test e-mail | `alerts.manage` | owner, manager |
| Run the checks now / dry run | `jobs.run` | owner, manager |
| Daily reports — read | `reports.view` | owner, manager, analyst (the roles that may see profit) |
| Generate / regenerate a report | `reports.generate` | owner, manager |
| System → Scheduled jobs — read | `jobs.view` | every role; *Run now* needs `jobs.run` |

The server enforces every one of these on every call; a refused call changes nothing and writes no audit row. An analyst or support user sees that
e-mail alerts are on, not whom they go to. The dashboard shows a **Business alerts** card (the same alerts the page shows) and the menu shows a badge
with the number nobody has acknowledged.

### Routes

```
GET  /admin/api/alerts?status=active|resolved|all&severity&type&page   list + counts + kinds
GET  /admin/api/alerts/checks                                          each check's last verdict + the watchdog job's last run
GET  /admin/api/alerts/settings        PUT  /admin/api/alerts/settings  {thresholds:{…}, enabled:{…}}
PUT  /admin/api/alerts/mail            {enabled, to, from, min_severity, cooldown_min}
POST /admin/api/alerts/mail/test
POST /admin/api/alerts/{id}/acknowledge      POST /admin/api/alerts/{id}/resolve
GET  /admin/api/reports?from&to&page                                   history (+ finished days that have no report)
GET  /admin/api/reports/{YYYY-MM-DD}                                   one report with its definitions
POST /admin/api/reports/{YYYY-MM-DD}/generate  {force?}                create the day's report, or rebuild it (force)
POST /admin/api/jobs/{job}/run                 {dry_run?}              the generic locked, recorded run (also for watchdog and report)
```

Writes accept an `Idempotency-Key` header: a double click is a replay (`X-Idempotent-Replay: true`), not a second action or a conflict.

---

## 5. Settings keys

All in `admin_settings` (JSON values), nothing new in the schema:

* `watchdog.<name>` — the 30 thresholds; `watchdog.enabled.<check>` — the switches (`provider_balance` defaults to off);
  `watchdog.state` — what each check saw last (verdict, one-line summary, figures, quiet-run counters).
* `alerts.email_enabled`, `alerts.email_to`, `alerts.email_from`, `alerts.email_min_severity`, `alerts.email_cooldown_min`.

## 6. Files

`app/Services/Watchdog.php`, `app/Services/DailyReport.php`, `app/Services/Alerts.php` (+ e-mail settings and the test message),
`app/Services/Jobs.php` / `JobHandlers.php` (the `watchdog` and `report` jobs, the daily schedule), `app/Services/SmmProviders.php` (`checkBalance`),
`app/Controllers/Admin/AlertsController.php`, `ReportsController.php`, `app/Views/admin/alerts.php`, `reports.php`,
`resources/js/admin/alerts.js`, `reports.js`, `system.js` (job table), `dashboard.js` (alerts card).
