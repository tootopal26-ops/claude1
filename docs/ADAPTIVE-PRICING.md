# Adaptive pricing & AI sales optimization engine

A measurable, auditable price-experimentation engine for eligible services, plus three admin screens backed entirely by real
data — the **Price Experiment Simulator**, **Profit Guard** and **AI Learning History** — and an optional, advisory-only AI
explanation layer. This is Phase 8 of the master prompt; the backend (`App\Services\Pricing`, `App\Services\PricingAi`) and the
admin UI are implemented and tested (`php tests/run.php PricingTest PricingRulesTest`).

## 1. The one rule that matters

**`services.price_paise` stays the single source of truth.** Nothing in this engine changes it except one explicit, audited,
owner-only action (`applyWinner`) taken after an experiment has already concluded with a winning result. Every other moment —
proposing an experiment, running it as a dry run, running it live, pausing it, rolling it back — only ever decides what *one
cohort of one experiment* is shown, for as long as that experiment runs. A visitor who is not part of a running experiment, and
every visitor once an experiment ends, sees the service's normal price. An order always keeps the exact price it was shown;
nothing here ever repr­ices history.

## 2. Safety is in the construction, not a setting someone has to remember

* **A new experiment always starts as a dry run.** `mode = dry_run` means *every* cohort — including the one labelled
  "treatment" — is shown the real, current default price. This is a genuine randomised **A/A test**: it exercises the whole
  assignment → qualification → purchase → rollup pipeline against real traffic, with zero price risk, so an owner can see the
  pipeline behaving correctly (stable cohorts, sane counts) before anyone is ever shown a different price. Only `goLive()`
  (permission `pricing.approve`, **owner only**) switches a cohort to the candidate price — and even then, a visitor who was
  already assigned before the switch keeps the price they were already shown; only *new* assignments see the live split.
* **Eligibility is restrictive by default.** Only explicitly enabled (`services.adaptive_enabled = 1`), **MANUAL**-mode services
  are ever considered — AUTO (API) prices are never touched by this engine. A service with no configured fulfilment cost is
  ineligible, full stop: *a missing cost is never treated as free*. A service already has an experiment in progress, or is in
  its post-experiment cooldown, cannot start another. A global pause or a per-service pause stops **all** new assignments at
  once; neither ever rewrites a price an order already carries.
* **The candidate price itself is bounded.** It must obey the administrator's price-ending rule (§3), must differ from the
  control price, must sit inside the service's configured minimum/maximum (or the global default), and cannot move more than
  the configured maximum change in one experiment (`pricing.max_price_change_bps`, default 50 %).
* **Automatic rollback is the real safety net.** Every run of the `pricing` cron job (every 10 minutes) — and every manual
  "Evaluate now" — recomputes real cohort metrics from `orders` and `pricing_assignments` and checks, in this order, for any
  **live** experiment: an unexpected change to the service's own default price, a service cost increase since the experiment
  started, inconsistent or missing price attribution on its orders, a breach of the configured profit floor, an elevated
  payment/order failure rate in the candidate cohort, a conversion decline beyond the configured tolerance, and finally the
  maximum permitted duration. The first one that is true ends the experiment immediately: the control price is restored for
  every new visitor, the exact reason and the before/after prices are recorded (`price_audit_logs`, when a price had actually
  been promoted), a cooldown is set, and a Business Watchdog alert is raised (`pricing.rollback`) so it shows up on the
  **Alerts** page as well as on **Profit Guard**.
* **A winner is never auto-applied.** Even a conclusive, profitable "winner" decision only changes the experiment's own status
  (`concluded`) — activating the candidate price as the new default needs a second, separate, owner-only click
  (`applyWinner`), which is itself price-versioned and audited exactly like any other price change made from the Services page.

## 3. The price-ending rule (`App\Support\PricingRules`)

A pure-function rule, independent of the database, so it is unit-tested directly:

* The candidate must be **whole rupees** (no paise fraction).
* Its **last digit must be 4 or 9** (₹24, ₹29, ₹34, ₹39, ₹44, ₹49 … are valid endings).
* Its **leading digit must be at least 2** — ₹4, ₹9, ₹14 and ₹19 are rejected even though ₹4/₹9/₹14/₹19 end in 4 or 9, because a
  single-digit or `1x` amount is "too small" for the rule's intent (there is no genuine tens-or-above leading digit yet).
* It must be at or above the configured minimum (`pricing.min_price_paise`, default ₹20, or the service's own
  `adaptive_min_paise`) and at or below its configured maximum, when one is set.
* This rule applies **only** to adaptive price candidates — an existing default price is never forced to match it, and
  rounding can never be used to sneak a candidate past a cost or margin floor (the floor checks run on the *exact* candidate,
  never a rounded one).

## 4. Cohort assignment (privacy-conscious, stable, never an IP)

A visitor's assignment key is `HMAC-SHA256` of their **existing** first-party cookie (`smb_v`, the same cookie `App\Services\Tracking`
already sets for analytics) under a purpose-bound sub-key — no new cookie is minted, no raw IP address is ever used as an
identity, and the key itself is never reversible to the cookie. The cohort (`control`/`treatment`) is a deterministic hash of
that key plus the experiment id, bucketed against the experiment's configured treatment share — so:

* The **same visitor always lands in the same cohort** for a given experiment, including after their assignment's TTL
  (`pricing.assignment_ttl_days`, default 30) expires and is renewed — a visitor cannot "re-roll" a better price by refreshing,
  waiting, or clearing only some cookies (clearing the `smb_v` cookie itself makes them look like a brand-new visitor, exactly
  as it does for analytics — this is stated plainly, not hidden).
* `qualified_at` on the assignment uses the **same 3-second qualification rule** as the rest of the site
  (`App\Services\Tracking::qualify`) — a visitor who passes it has every one of their live assignments marked qualified in the
  same call, with no extra round trip.
* `service_viewed_at` is set the first time the price is resolved for that visitor+service (a storefront card render or, if
  that never happened, the checkout call itself) — this is what the "service-page visitors" metric in Profit Guard / the
  experiment dashboard counts.

## 5. What each cohort's numbers mean (profit-aware, never "optimise conversion alone")

The evaluator recomputes, per cohort, directly from real rows (never estimated, never carried over from a different period):
**assigned** visitors, **qualified** visitors, **service views**, **checkout starts** (orders created, paid or not),
**verified purchases** (orders actually paid), **failed/cancelled** orders, **revenue** (net of refunds) and **contribution
profit** (revenue − provider/fulfilment cost − payment fee − other order costs — the exact same formula `App\Support\Profit`
and `App\Services\Finance` use everywhere else in this application, so a figure here never disagrees with Finance).

The decision rule compares **contribution profit per qualified visitor** (not raw conversion rate, and not raw revenue) between
the two cohorts, and only once *both* have reached the experiment's configured minimum sample size:

* **winner** — the candidate beats the control by at least the configured margin (`pricing.decision_margin_bps`, default 5 %).
* **loser** — the control beats the candidate by at least that margin.
* **inconclusive** — the difference is inside the margin, or a sample is still too small.
* **insufficient_data** — the maximum duration was reached without either of the above (this is itself one of the automatic
  rollback triggers: an experiment is never left to run forever on an ambiguous result).

## 6. Rollback triggers, exactly as checked (in order, first match wins)

1. The service's **own default price changed** outside the normal (blocked) path.
2. The service's **cost increased** beyond the configured tolerance since the experiment was proposed (checked against a
   snapshot taken at `propose()` time — this check runs even with zero orders yet, so a cost change cannot sit unnoticed for
   days waiting for traffic).
3. **Data integrity**: any order linked to the experiment that is missing its cohort or price-version stamp.
4. **Profit floor breach**: the candidate cohort's average contribution per order fell below the experiment's configured
   floor (`profit_floor_paise`, default ₹0 — never sell at a loss unless an admin explicitly set a positive floor).
5. **Payment/order malfunction**: the candidate cohort's failure rate (FAILED/CANCELLED ÷ checkout starts) is elevated well
   beyond the control cohort's, once both have enough checkout starts to compare.
6. **Conversion decline beyond tolerance**: the candidate cohort's verified-purchase conversion fell by more than the
   configured relative tolerance versus the control, once both have enough qualified visitors to compare.
7. **Maximum duration exceeded** without a conclusive winner/loser.

Every evaluation — including a **dry run of the cron job itself** (the job's own "would do nothing" preview mode, distinct
from an experiment's `dry_run` mode) — writes one `pricing_decision_logs` row with its reason and the cohort numbers it saw, so
"what did the engine think and when" is always reconstructable, whether or not anything was actually changed.

## 7. The three admin screens (**Admin → Adaptive pricing**)

All three read live database rows through `App\Services\Pricing` — nothing here is a mock or a placeholder number.

* **Price Experiment Simulator** — pick an eligible (or inspect an ineligible, and see exactly why not) MANUAL service, enter
  several candidate prices, and see, for each: whether it passes the price-ending rule, its contribution per order against the
  service's real configured costs, and a projection at the service's **real recent order volume** (the last 90 days, or its
  whole history if that window has fewer than 5 paid orders — the window used is always named). This is a volume-held-constant
  projection, explicitly **not** a demand forecast: no per-service visit/conversion history exists until a real experiment
  actually runs one, and the screen says so.
* **Profit Guard** — the global pause switch, every adaptive-enabled service's own pause switch, every **running** experiment's
  live profit-floor status (ok / at risk / breached, from its real treatment-cohort contribution), the recent automatic
  rollbacks (reason, before/after, cooldown), and the related Business Watchdog alerts.
* **AI Learning History** — every experiment that has ended (concluded, rolled back or cancelled), its control vs candidate
  price, both cohorts' real purchases/revenue/contribution side by side, its decision and the reason, whether its candidate
  was ever activated, and — on request — an AI-generated, clearly-labelled advisory note (§8) or, when no AI provider is
  configured, a deterministic rules-based one instead (`App\Services\PricingAi::rulesBasedInsight`, which states plainly that
  no AI model was used).

## 8. The optional AI layer (`App\Services\PricingAi`)

The same discipline as the daily report's AI commentary ([AI-AND-SUPPORT.md](AI-AND-SUPPORT.md)): off by default
(`ai.use_for_pricing`, independent of the reports/support switches), given only the experiment's own already-computed numbers
(never an order id, a customer link, or any figure it did not already know), required to reply in one strict JSON shape
(`{explanation, recommendation, confidence_note}`) or the reply is discarded as `invalid`, and always rendered **separately
from, and clearly labelled apart from**, the deterministic decision it is describing. It never decides a winner, never
triggers a rollback, and the system prompt explicitly tells it never to claim it changed anything.

## 9. Deliberately out of scope for this phase

To ship the three required features on real, working data without open-ended scope growth, two parts of the broader master
prompt were **not** built in this phase (they do not block anything above, and nothing here depends on them):

* The full **Advertising budget & CPC configuration** screen (daily budget, GST-inclusive/pre-tax basis, campaign-level
  forecasts) — `ad_campaign_settings` (migration 009) stays as unused schema, exactly as Phase 5b left it. CPC and the
  qualification window are already configurable (**Admin → Advertising**) and already feed the existing `Ads`/`AdsIntel`
  reporting (Phase 5b, [ADS-INTELLIGENCE.md](ADS-INTELLIGENCE.md)); only the budget/GST *forecast calculator* itself is
  deferred.
* The **platform-preference popup** ("Instagram or YouTube?" on a visitor's first visit). `visitor_sessions.platform_pref`
  (migration 009) stays unused; the storefront's existing platform tabs already serve the same practical purpose.

## 10. Tests

`tests/Unit/PricingRulesTest.php` (price-ending rule, pure functions) and `tests/Integration/PricingTest.php` (eligibility,
proposal validation, the dry-run A/A guarantee, stable cohort assignment across a go-live switch, the full permission matrix,
a clear winner decision, `applyWinner` plus the price-edit lock it creates, every rollback trigger individually, the cron
job's own dry-run mode, duration-exceeded handling, and the AI layer's off-by-default / advisory-only behaviour) — 18 tests,
all passing alongside the full existing suite (`php tests/run.php`, 372 tests at the time of writing).

## 11. Cron

One more scheduled job, `pricing` (every 10 minutes, cPanel line `*/10 * * * *`, supports `dry_run`): evaluates every running
experiment per §6 above. Like every job in this application it takes a per-job advisory lock, has a bounded time budget, is
recorded in `job_runs`, and never performs an unbounded or more-frequent-than-cooldown price action. See
[OPERATIONS.md](OPERATIONS.md) for the full cron line list.
