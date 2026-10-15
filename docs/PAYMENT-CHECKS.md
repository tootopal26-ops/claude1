# Payment checks

How a payment becomes "paid", what the shop flags when something about a payment looks wrong, and what an admin can do about it.
Everything here is real code with tests (see [§11](#11-tests-that-were-run-and-what-was-not)); nothing is simulated.

> **A flag is a signal for a person, never a punishment.** Nothing in this feature blocks a customer, bans a visitor, cancels an order or
> moves money. The things that *protect* money (§2) were already there and are unchanged; this feature records when they were not satisfied
> and when the evidence disagrees with itself.

## 1. Payment states

The database keeps the existing, tested payment states. Reports and the **Payment checks** screen speak the requested vocabulary through one
mapping (`Status::specPayment()`):

| Requested | Stored (`payments.status`) | Meaning |
|---|---|---|
| `CREATED` | `creating` | the row exists, the gateway session is being made |
| `PENDING` | `pending` | the customer has a payment session (5:00) and has not paid yet |
| `PAID` | `success` | money is recognised (§2); `verified_via` says by whom |
| `FAILED` | `failed` | the gateway reported a failure |
| `EXPIRED` | `expired` | the session ended unpaid |
| `REVIEW_REQUIRED` | `mismatch` | the gateway says paid but something does not match — **held**, nothing fulfilled, a person decides |
| `REFUNDED` | `refunded`, `partially_refunded` | an admin recorded a refund |

`REFUND_PENDING` does **not** exist: Alpha UPI has no refund API, so a refund is recorded when a person has made it.

## 2. How a payment becomes paid (unchanged rules, stated once)

A browser redirect, a query parameter, a click, a customer's screenshot or the QR being scanned are **never** proof of payment. Only:

1. a **signed webhook** (HMAC-SHA256 of the raw body with the webhook secret, compared in constant time), or
2. the shop's **own server-to-server status lookup** (`check-status` / `check-order-status`) — the status poll, the cron sweep, the **Re-check** button, or
3. an **admin's manual confirmation** — an explicit, audited decision, recorded as `verified_via = admin`, never presented as a gateway confirmation,

can mark a payment paid, and `Settlement::settle()` does it under a row lock only when **all** of these hold:

* the reported amount equals the amount **we stored** for that payment (integer paise);
* the currency is INR (a gateway that reports anything else is held; the shop is INR-only);
* the bank reference (UTR) has not been used by another payment on that gateway;
* the payment belongs to the gateway the notification came from;
* the order is in a state that can be paid.

If one fails, the payment becomes `mismatch` (REVIEW_REQUIRED), the order stays unpaid, **nothing is fulfilled**, and a flag explains why (§4).
Only then is the order routed: AUTO orders go to the SMM provider **after** the HTTP answer has been sent, MANUAL orders enter the manual queue.
Success is final: a later `FAILED` / `EXPIRED` notification cannot undo it (it is flagged instead).

### What the gateway documents (and what this code relies on)

Confirmed from the integration and the vendor SDK in the repository ([API.md](API.md), `app/Vendor/AlphaUpi`) — nothing else is assumed:

| Capability | Alpha UPI |
|---|---|
| Create a payment | `POST /api/create-order` → `data.payment_url` (hosted checkout), `order_id`, `token`, `amount`, `currency`, `expires_at` |
| Verify a payment | `POST /api/check-status` (current API) / `/api/check-order-status` (older API) → `payment_status`, `amount`, `utr`, `payment_method` |
| Webhook signature | HMAC-SHA256 of the raw body, hex, in a header whose name contains "signature" |
| Event id | **none** in the current payload → de-duplication by the SHA-256 of the body (§3) |
| Refunds | **no API** |
| Fees, balance | **not reported** |
| QR / UPI intent | **not documented** (see [ORDERS.md §3a](ORDERS.md)) |

Not tested against a live Alpha UPI account — only against a protocol-faithful mock (`tools/mock-alpha-upi`). Make a ₹5 test order in test mode before going live.

## 3. Webhook idempotency and duplicate protection

* **Unique constraints:** `payments.gateway_order_id` (unique), `payments (gateway_id, provider_txn_id)` (a bank reference pays one payment), `webhook_events.dedupe_key` (unique, set **only** for verified events so a forged event can never use up a real event's id).
* **Event id, or a safe fingerprint:** when the payload carries `event_id` it is the key; the current Alpha UPI payload carries none, so the key is `body:<sha256 of the raw body>`. An identical redelivery is therefore recognised; a *different* delivery for an already-paid payment (e.g. a second bank reference) is handled by settlement, not by the key — it never double-counts (§4 `duplicate_payment`).
* **Concurrency:** settlement runs under `SELECT … FOR UPDATE` on the payment; 8–20 simultaneous deliveries produce one settlement and one provider order (tested with real parallel PHP processes).
* **Audit of ignored duplicates:** a repeated delivery is acknowledged (`200 duplicate`), not processed again, and counted on the original event row: `webhook_events.attempts` (every delivery) and `last_attempt_at` (when the latest repeat arrived — migration `010`). The Payment checks screen shows "N deliveries · M repeats ignored".
* A crashed first delivery (event row left `received` or `error`) is processed on the next delivery; settlement is idempotent, so this is safe.

## 4. What gets flagged

One live flag per problem — enforced by the database (`payment_flags.active_key` is unique while a flag is open and `NULL` once it is closed). Seeing the same problem again raises `occurrences` and refreshes the facts; a recurrence after a review opens a new flag. Details hold plain facts only (amounts in paise, references, statuses) — never payloads, secrets or personal data.

| Flag | Default risk | Raised when | Closes |
|---|---|---|---|
| **Amount mismatch** | high | the gateway reported another amount than the order | a person (the payment is held) |
| **Currency is not INR** | high | the gateway reported another currency | a person |
| **Amount not reported** | medium | "success" without an amount | a person |
| **Bank reference already used** | high | the UTR belongs to another payment | a person |
| **Paid more than once** | medium | a second payment / bank reference arrived for an order that is already paid | a person (refund the extra by hand) |
| **Paid after the session ended** | low | confirmed more than 120 s after the 5:00 session expired (switchable) | a person, or automatically after 14 days |
| **Unknown order reference** | medium | a *correctly signed* notification named a payment this shop does not have (one flag per reference) | a person |
| **Gateway answers disagree** | high | the webhook and the gateway's own lookup differ — kinds: `webhook_not_confirmed`, `amount_differs`, `currency_differs`, `reference_differs`, `failed_after_paid`, `expired_after_paid` | automatically when a re-check agrees; otherwise a person |
| **Webhook signatures failing** | medium → high | ≥ N rejected signatures per gateway in an hour (high at 5 × N) | automatically when the hour is quiet |
| **Repeated unfinished payments** | low | one visitor with ≥ N failed/expired payments in the window and none paid | automatically when they pay or the window passes |
| **Order and payment disagree** | high | found by the scan: a paid payment on an unpaid order, a paid order with no paid payment, a payment whose amount differs from its order, a recorded paid amount that differs from the expected one, or money that arrived for a cancelled order | automatically once the records agree again |

A **high** flag also raises a **critical** Business Watchdog alert (`business_alerts`, one live alert per flag, closed with it); it is e-mailed only if the owner turned alert e-mail on (off by default, `alerts.email_*` settings). The e-mail is sent after the database transaction has committed and contains no credentials and no customer data. Medium and low flags appear on the screen and in the sidebar badge (medium + high), not in the e-mail.

Nobody is punished by a heuristic: a flag never blocks a visitor, never cancels an order, never changes a price.

## 5. Cross-checking webhooks with the gateway

Setting **Payment checks → Thresholds → Cross-check every accepted webhook** (`risk.webhook_double_check`):

| Mode | Behaviour |
|---|---|
| `off` | the signed webhook is trusted on its own (as before this feature) |
| `flag` *(default)* | credited on the signed webhook as before; **after the response** the gateway is asked. A different amount / currency / reference is flagged at once. A "not paid" answer is only flagged after a grace period (default 120 s — the gateway may just be catching up): the `payments` cron job asks again and flags it then. The payment is never undone. |
| `require` | the webhook is only a hint: the payment is credited from **our own lookup** (`verified_via = poll`). If the gateway cannot be asked, the answer is `503` so the gateway delivers again (the event row is left `error`, the next delivery is processed). A webhook the gateway does not confirm credits nothing and is flagged. |

Payments an admin confirmed by hand are never cross-checked (the gateway may legitimately not know them).

## 6. The screen — Admin → Sales → Payment checks

* **Summary:** paid in 30 days (with pending / failed / expired), payments held for review, open flags (high · medium · low), webhooks in 24 h (rejected, repeats ignored). All numbers come from the tables.
* **Payments tab:** for every payment — order id, gateway reference, bank reference (UTR), expected and verified amount, currency, state (stored chip + requested name), verification source (*Signed webhook* / *Gateway lookup* / *Confirmed by hand*), created / verified / expires timestamps, last gateway check, flags, latest webhook status and delivery counts. Filters: All · Flagged · Needs review · Pending · Paid · Failed · Expired · Refunded, and search by order id, gateway order id or bank reference.
* **Flags tab:** each flag with what it means, what to do, the facts behind it, when it was first / last seen and how often, and its review. Filters by open / closed / all, severity and kind.
* **Thresholds tab:** the numbers that decide when a flag is raised (defaults and limits below); every change is written to the audit log.
* **Safe reconciliation actions:**
  * **Re-check** — asks the gateway about that one payment right now (rate-limited 30 per 5 min per admin, audited as `payment.rechecked`). An unpaid payment is settled, failed or expired by exactly the rules the automatic poll uses; a paid one is compared with the answer; **a flagged (`REVIEW_REQUIRED`) payment is never released by it**; an unreachable gateway is reported as such and nothing changes.
  * **Reviewed / False alarm** — closes a flag with who, when and a note (a note is required to dismiss); audited.
  * **Order** — opens the order, where an **owner** can use *Confirm payment manually* after checking the bank statement (§8).

## 7. Thresholds

| Setting | Default | Allowed | Meaning |
|---|---|---|---|
| `risk.invalid_signatures_per_hour` | 5 | 1–1000 | rejected signatures per gateway per hour before a flag |
| `risk.failed_attempts` | 4 | 2–50 | failed/expired payments by one visitor (none paid) before a flag |
| `risk.failed_window_hours` | 24 | 1–168 | how far back those are counted |
| `risk.confirm_grace_seconds` | 120 | 30–3600 | how long a missing gateway confirmation is tolerated after a webhook |
| `risk.flag_late_payments` | on | on/off | the informational late-payment flag |
| `risk.webhook_double_check` | `flag` | off / flag / require | §5 |

Stored values outside the limits are clamped when read, so a bad database value can never disable or flood the checks.

## 8. Who may do what

Server-enforced (`403 forbidden`); the buttons are hidden for the rest, but hiding is a convenience, not the protection. Roles are assigned with `php bin/admin.php role --username=… --role=…`.

| Action | owner | manager | support | analyst |
|---|:-:|:-:|:-:|:-:|
| See payments, flags, the monitor (`payments.view`) | ✔ | ✔ | ✔ | ✔ |
| Re-check; review / dismiss a flag (`payments.review`) | ✔ | ✔ | — | — |
| Change thresholds (`alerts.manage`) | ✔ | ✔ | — | — |
| **Confirm a payment by hand** (`payments.override`) | ✔ | — | — | — |

A manual confirmation requires the bank reference the admin verified it with, goes through the same settlement code (so the amount, the one-time reference and the row lock still apply), is stored as `verified_via = admin`, is written to the audit log (`payment.confirmed_manually`), and is never labelled as a gateway confirmation.

## 9. Scheduled work

The `payments` job (`php bin/cron.php payments`, suggested `*/5 * * * *`; also run by the one-line `php bin/cron.php` every minute when it is due) does, under a per-job lock and a time budget:

1. the existing sweep: expires unpaid sessions after asking the gateway once more, fails sessions stuck in "creating", re-checks recently expired sessions so a late payment is not lost;
2. the **risk scan**: bursts of rejected signatures, repeated unfinished payments, order/payment records that disagree (last 14 days), the cross-check of webhook-paid payments the gateway has not been asked about (10 per run, stops when the gateway is unreachable), closing flags whose condition no longer holds, and closing informational late-payment flags after 14 days.

`php bin/cron.php payments --dry-run -v` shows what a run would do without writing anything or asking the gateway. Each run is recorded in `job_runs` (start, finish, small result, error *category* only). Flags that are closed are deleted after a year by the cleanup job.

## 10. Data kept

`payment_flags` (details: amounts, references, statuses — no payloads, no secrets), `webhook_events` (180 days; payload excerpt, signature validity, attempts), `job_runs` (90 days), `audit_logs` (re-checks, reviews, threshold changes, manual confirmations). No card data, no UPI PINs, nothing the gateway does not already show in its own dashboard.

## 11. Tests that were run — and what was not

Run on this build (MariaDB 10.11, PHP CLI, mock gateway + mock SMM panel):

* **PHP:** 28 tests in `tests/Integration/RiskTest.php` (flag dedupe and details hygiene; amount / currency / missing-amount / reused-reference holds; paid-twice; late payment; unknown reference; failed-after-paid; signature bursts and their automatic close; duplicate counting; the three cross-check modes including a `503` retry; the scan's record checks, repeated failures and dry-run; alert e-mail sent after commit and closed with the flag; the scan closing only what it derives itself; review rules; re-check rules, audit and rate limit; manual confirmation not second-guessed; thresholds validation and clamping; monitor columns, filters and summary; permissions of every action) plus the existing `PaymentFlowTest` / `AlphaUpiApiTest` suites (the whole PHP run: see [QA-REPORT.md](QA-REPORT.md)).
* **Browser** (`tools/qa/admin-checks.mjs`, real Chromium against the dev server and the mock gateway): real checkouts paid, mis-paid, unknown, lost and unconfirmed through signed webhooks; the monitor, filters, re-check, the cron scan, review / dismiss dialogs, thresholds, axe (WCAG 2.2 AA), keyboard, phone and tablet layouts, and the role matrix (support, manager, owner) including refused API calls.

**Not tested:** a live Alpha UPI account (no network path to it from the build environment, and no real payment was made); the behaviour of the real gateway's `check-status` for an order it no longer has; very large `payments` tables (the screen and the scan are indexed and paged, but were exercised with a few hundred rows).

## 12. Known limits

* Alpha UPI sends no event id, no fee, no balance, and has no refund API: duplicates are recognised by body hash and by bank reference; refunds are recorded by hand; payment fees are an estimate set in the profit settings (a later phase).
* A "not paid" gateway answer right after a webhook can be a timing artefact; that is why it is flagged only after the grace period.
* In `require` mode a gateway outage delays crediting until the gateway answers (the customer's page keeps polling, and the gateway re-delivers).
* The risk scan looks at the last 14 days of records and the last 24 hours of webhook-paid payments; older inconsistencies are visible in the Payments page and the audit log but are not re-flagged.
