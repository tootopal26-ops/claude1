# Order recovery

Which **paid** orders are not where they should be, why, and what a person can safely do about each — plus the safe automatic retries that
need no person. Everything here is real code with tests (see [§10](#10-tests-that-were-run-and-what-was-not)); nothing is simulated.

> **The rule this feature is built around:** the SMM API (v2) has **no idempotency key and no lookup by link**. If the shop sends an order
> and the answer is lost (timeout, dropped connection, garbled answer), nobody — not the shop, not an AI — can know whether the provider
> has the order. Resending could buy it twice. So an outcome nobody can know is **never retried by itself**: a person looks in the
> provider's panel and either links the order that is there, or confirms there is none and resends. There is no guessing and no "smart retry".

## 1. What is in the queue

The queue is **derived live from the `orders` table** on every request — there is no stored copy that can go stale, so the numbers on the
screen, the sidebar badge and the job's report cannot disagree with the data. An order can be in more than one category (the most urgent is
shown first).

| Problem (key) | An order is in it when… | Who acts |
|---|---|---|
| **Provider outcome unknown** (`unknown`) | `FAILED` with `failure_kind = ambiguous` — the provider may or may not have it (timeout after sending, dropped connection, non-JSON / unclear answer, an answer with no order id, a crash while sending, or sending that was interrupted and released after 10 minutes) | a person |
| **Needs review** (`review`) | `recovery_status` is `needs_review` or `escalated` (set by the shop for an unknown outcome or a misconfiguration, or by a person) | a person |
| **Failed** (`failed`) | `FAILED` for a known reason: the provider refused it (bad link, wrong service id, no funds …), a service that is not set up for AUTO, or retries ran out | a person |
| **Sending is stuck** (`sending`) | `PROCESSING` for more than 5 minutes (after 10 the watchdog hands it to a person as an unknown outcome) | the shop, then a person |
| **Repeated provider errors** (`errors`) | 3 or more failed status lookups for a live provider order in 2 hours | follow-up |
| **Provider not followed** (`stale`) | at the provider (`SUBMITTED` / `IN_PROGRESS`), status not refreshed for 30 minutes | the shop (the job syncs) |
| **Waiting to be sent** (`waiting`) | a paid `AUTO` order, not sent yet, due now (this normally takes seconds) | the shop (the job sends) |
| **Retry scheduled** (`retry`) | the provider could not be reached (nothing was sent) and the next attempt is in the future | the shop |

An order is out of the queue when it is delivered and followed, cancelled, refunded, in the manual queue, or when a person marked it
**resolved**. Unpaid orders are never in it.

## 2. What is retried by itself — and what never is

| Situation | Automatic? | Why |
|---|---|---|
| The provider **could not be reached** (connection refused, DNS) — nothing was sent | **Yes**, with back-off: **60 s, 5 min, 20 min, 1 h**, then a person is asked. Up to *maximum attempts* (default **5**, 1–10, [§5](#5-the-retry-policy)) | nothing reached the provider, so nothing can be duplicated |
| A timeout, a dropped connection **after** sending, a garbled or unclear answer, an answer without an order id, a crash while sending | **Never** | the provider may hold the order (`unknown`) |
| Sending was interrupted (the process died while the order was claimed) | **Never** — handed to a person after 10 minutes | same |
| The provider **refused** the order (bad link, unknown service, no funds, …) | **Never** — the reason is shown and a person fixes the cause | a retry cannot succeed until something changes |
| A paid order is waiting to be sent | **Yes** — the job sends it (a person marked *needs review / escalated* pauses this) | — |
| A live provider order | **Yes** — the job refreshes its status every few minutes | read-only toward the provider |

Duplicate protection that was already in place and is unchanged: an order is **claimed** (`PAID → PROCESSING`) under a row lock before the
provider is called, so two processes (the customer's request, the cron job, an admin's click) cannot both send it; a provider order id is
never overwritten while the order is live; a retry of an order that has a live provider order is refused.

## 3. What a person can do (and the rules each action keeps)

Every action: needs `orders.recover`, is **checked against the order's state on the server** (`409 not_allowed` otherwise), writes a line to the
order's timeline, is audited (`order.recovery.*`), and is idempotent per click (the browser sends an `Idempotency-Key`).

| Action | Offered when | What it does | Safety rule |
|---|---|---|---|
| **Send now** (`retry_submit`) | paid, not at the provider (waiting, retry scheduled, failed for a known reason) | sends the order to the provider now, ignoring the back-off | re-reads the service, so a corrected SMM service id / cost applies; the claim lock still applies, so a double click sends once |
| **Resend after checking…** (`retry_submit` on an unknown outcome) | the outcome is unknown | sends again | refused (`422 verification_required`) unless the request says `verified_absent: true` **and** carries a note of at least 5 characters; the browser makes the person type **CHECKED** and say what they looked at; the note is written to the order's timeline |
| **Link provider order…** (`link_provider_order`) | failed, no provider order id | adopts the order id the person found in the provider's panel; the order then follows the provider's status | the provider is asked about that id with its own `status` call and **must know it** (`409 not_confirmed`, nothing changes); an id already linked to another order is refused; the check and the change happen under a named lock so two admins cannot link the same provider order |
| **Move to manual queue…** (`move_to_manual`) | paid, not live at the provider | stops sending it to the provider; it waits in the manual queue for approval and delivery by hand | asks first; refused when the order is live at the provider (sync it instead) |
| **Sync** (`sync`) | at the provider | asks the provider for the order's status now | read-only toward the provider |
| **Mark for review…** (`mark_review`) | waiting / sending / failed / live | pauses automatic sending of a waiting order and keeps it in *Needs review* | note optional |
| **Escalate…** (`escalate`) | as above | like *mark for review*, and records **who and when** | note required (3+ characters) |
| **Resolve…** (`resolve_review`) | marked, or failed | closes the review; automatic sending of a waiting order carries on | note required (3+ characters); the note is on the timeline |

The primary button on a row is the safest useful one: *Link order…* for an unknown outcome (never *Resend*), *Send now* for a failure or a
waiting order, *Sync* for an order that is not followed, *Resolve…* for a review. **More** lists the rest, each with what it does.

**What the shop cannot verify:** the SMM API's `status` call does not return the link the provider order was made for, so *Link provider
order* proves the provider **has an order with that id**, not that it is for this customer's link. Compare the link in the provider's panel
before you link.

## 4. The screen — Admin → Sales → Order recovery

* **Cards:** *Needs a person* (unknown · review · failed), *Waiting or retrying*, *Provider follow-up* (stuck · repeated errors · not followed), and the *Recovery job* (running / overdue / failing / never run, last run and whether it was a dry run). All numbers come from the tables.
* **Queue:** one row per order — id and status, service and quantity, the account link, when it was paid; the problem(s) and the latest error; attempts (*n of max*), last attempt and next scheduled attempt; the provider order and status; the primary action, **More** and **Open order**. Filters per problem (with counts) and a search by order id, link or provider order id; the address bar remembers them. The meaning of each problem is in a *What the problems mean* legend (and as a tooltip on each chip) rather than repeated on every row.
* **Retry policy:** the maximum number of attempts, the schedule spelled out, and what is and is never retried by itself.
* **Run recovery now / Dry run** (permission `jobs.run`): the same code the cron job runs. A dry run reports what *would* be sent, followed and handed over, and changes nothing and asks the provider nothing.
* The **order drawer** (Orders) shows a *Recovery* section — why the order is in the queue, attempts, last / next attempt, latest error, who escalated it — and offers the same actions with the same dialogs.
* The sidebar item carries a badge with the number of orders that need a person. Phone: each order is a labelled card; every control is reachable by touch.

## 5. The retry policy

| Setting | Default | Allowed | Meaning |
|---|---|---|---|
| `recovery.max_attempts` | 5 | 1–10 | how many times an AUTO order is sent (counting a manual *Send now*) before a person is asked; stored values outside the limits are clamped when read |

Changing it needs `orders.recover` (owner, manager), is validated on the server (`422` with the field), and is audited (`recovery.settings_changed`).
The back-off steps themselves (60 s, 5 min, 20 min, 1 h) are fixed in code.

## 6. Scheduled work

`php bin/cron.php recovery` (suggested `*/5 * * * *`; also run by the one-line `php bin/cron.php` every minute when it is due), under a
per-job lock and a time budget, recorded in `job_runs`:

1. **hand over stuck sending** — an order claimed for sending that got no answer for 10 minutes becomes *unknown outcome* for a person (it is never resent);
2. **send due orders** — up to 20 paid AUTO orders that are waiting or whose retry time has come (a person's *needs review / escalated* mark pauses an order);
3. **follow live provider orders** — refresh the status of orders at the provider (batches, the time budget is respected);
4. **tidy the marks** — an unknown outcome or a misconfiguration always carries the *needs review* mark (also for orders that failed before this release); an order that left the recovery states (delivered, cancelled, refunded, in the manual queue) is marked *resolved*.

`php bin/cron.php recovery --dry-run -v` prints what a run would do and writes nothing. Admin → Order recovery shows the last run (time,
status, whether it was a dry run) and whether the job is running, overdue, failing or has never run; the full scheduled-jobs health list
(`GET /admin/api/jobs`) is built on the same run history and gets its own screen in a later phase.

## 7. Who may do what

Server-enforced (`403 forbidden`); the buttons are hidden for the rest, but hiding is a convenience, not the protection.

| Action | owner | manager | support | analyst |
|---|:-:|:-:|:-:|:-:|
| See the queue, the policy and the order's Recovery section (`orders.view`) | ✔ | ✔ | ✔ | ✔ |
| Every action in §3 and the retry policy (`orders.recover`) | ✔ | ✔ | — | — |
| Run recovery now / Dry run (`jobs.run`) | ✔ | ✔ | — | — |

The order drawer's endpoint (`POST /admin/api/orders/{id}/action`) maps the recovery actions to `orders.recover` too, so the drawer cannot be used to get around the role.

## 8. Data

No new table. The columns that came with the earlier migrations are used: `orders.provider_attempts`, `last_attempt_at`, `next_attempt_at`, `last_error`, `failure_kind` (`unreachable` · `rejected` · `ambiguous` · `misconfigured`), `recovery_status` (`auto_retry` · `needs_review` · `escalated` · `resolved`), `escalated_at`, `escalated_by`; `smm_transactions` (every provider call, without the key); `order_status_history` (every action, with the person and the note); `audit_logs` (`order.recovery.retry` · `.linked` · `.sync` · `.review` · `.escalated` · `.resolved`, `order.action` for the manual move, `recovery.settings_changed`); the setting `recovery.max_attempts`; `job_runs`. Error text shown on the screen is cleaned (no keys, no URLs with credentials).

## 9. Approve All

*Approve All* (manual orders) leaves out an order whose payment has an open **high** payment flag ([PAYMENT-CHECKS.md](PAYMENT-CHECKS.md)); it can still be approved one by one with the flag in front of the admin. The Orders page says how many were held back.

## 10. Tests that were run — and what was not

Run on this build (MariaDB 10.11, PHP CLI, mock SMM panel + mock gateway):

* **PHP:** 19 tests in `tests/Integration/RecoveryTest.php` — the recovery marks (every attempt stamped, a scheduled retry vs a later success, an unknown outcome or a setup problem needing review, a plain refusal being a known failure); the retry policy (a setting of 1–10, the schedule growing then staying at an hour); the queue (every category derived from the orders, an order in two categories, unpaid / manual / delivered orders never shown, the order of urgency); resending (a known failure once and audited, an **unknown outcome only after the statement and note**, the cron never resending an unknown outcome, a person's mark pausing automatic sending, **two admins resending in the same instant creating one provider order** — a real second PHP process); linking (verified with the provider, **no second order**, an id linked elsewhere refused even when two admins race); review / escalate / resolve; move to manual (a live provider order is never abandoned); sync (an unreachable provider changes nothing); the job (a dry run changes nothing, a real run sends what is due and records the queue); Approve All leaving out orders with an open high payment flag; and the permissions of every action, in the queue and through the order drawer, and of "run now" (jobs.run). The existing `FulfilmentTest` covers the claim lock and the hand-over of interrupted sending.
* **Browser** (`tools/qa/admin-recovery.mjs`, 27 steps, real Chromium against the dev server, the mock SMM panel and the mock gateway): orders put into every state through the real code paths; the cards, chip counts and sidebar badge equal the database; every row's content; filters, search and the address bar; every action with its dialogs and refusals (a wrong provider id, an id linked elsewhere, no *CHECKED*, missing notes), a double click sending once, a failure the provider refuses again, the manual move, the retry policy (validation, audit, effect on every row), Dry run (an order fingerprint before and after) and Run now, the order drawer, axe (WCAG 2.2 AA + best practice) on the queue, the policy, the *More* dialog and an action dialog, keyboard, 1440 / 1366 / 1100 / 820 px with no horizontal scroll (the table fits its card from 1366 px), a 390 px phone (labelled cards, touch), and the role matrix (support, analyst, manager) including refused API calls and nothing changed.

**Not tested:** a live SMM panel (the mock reproduces the documented v2 answers and the failure modes — timeouts, garbled answers, refusals — but no real provider was contacted); a real provider's behaviour after a lost answer (does it hold the order? only its panel can say); very large `orders` tables (the queue is derived with indexed predicates and paged 25 at a time, but was exercised with a few dozen rows); MySQL 8 for the Phase 4 tests (the Phase 9 run).

## 11. Known limits

* No idempotency key / lookup by link in SMM API v2: an unknown outcome needs a person with the provider's panel. This cannot be automated safely, and the screen says so.
* *Link provider order* confirms the provider knows the id, not that it belongs to this link (the `status` answer carries no link).
* A provider that reports an order as failed / cancelled makes it eligible for *Send now* again (the old provider order id is replaced); the history keeps the previous id in the timeline and `smm_transactions`.
* Refunds are not part of recovery: cancelling a failed order and refunding the customer is a recorded decision in the order drawer (there is no refund API).
* The table of the queue shows 25 orders per page; with more than that, filter or search.
