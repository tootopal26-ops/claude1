# Orders & payments: lifecycle, routing and failure handling

## 1. The journey of one order

```
 customer                    server                                         gateway / provider
 ────────                    ──────                                         ──────────────────
 BUY NOW → link ✓ valid
 PROCEED ───────────────▶ POST /api/checkout
                            • price, quantity, mode come from the DB
                            • order PENDING_PAYMENT + payment "creating"
                            • ask the gateway for a QR ──────────────────▶ create-order
                            • payment "pending", expires in 5:00 ◀─────────
 ◀── /pay/{ref} ──────────  SCAN AND PAY (QR, 05:00 countdown)
 pays with any UPI app ──────────────────────────────────────────────────▶ bank
                            signed webhook ◀────────────────────────────── SUCCESS + amount + UTR
                            (or the page/cron verifies with the gateway)
                            Settlement (row lock): amount matches? UTR unused?
                              AUTO   → PAID → PROCESSING → SUBMITTED ───────▶ SMM provider "add"
                              MANUAL → PAID → MANUAL_PENDING (queue)
 ◀── "Payment successful" / tracking page
                            cron: sync provider status + real cost ◀────────  In progress / Completed
                            admin: Approve / Approve All (MANUAL) → MANUAL_APPROVED → COMPLETED
```

## 2. Order statuses (13) and allowed transitions

| Status | Meaning (customer sees →) |
|---|---|
| `PENDING_PAYMENT` | created, waiting for money ("Waiting for payment") |
| `PAID` | money confirmed, not yet handed over ("Payment received — starting your order"). Also the *retry* state when a provider is unreachable. |
| `PROCESSING` | claimed for submission (a row-locked state: only one process may call the provider) |
| `SUBMITTED` / `IN_PROGRESS` | accepted by the provider / being delivered ("Order placed" / "In progress") |
| `COMPLETED` / `PARTIAL` | delivered / partly delivered |
| `MANUAL_PENDING` | paid MANUAL order waiting for an admin ("being processed by our team") |
| `MANUAL_APPROVED` | admin approved, delivery under way |
| `FAILED` | needs a human ("Needs attention — our team has been notified"; the reason is visible to admins only) |
| `CANCELLED` / `REFUNDED` | stopped / money returned (refunds are *recorded*: Alpha UPI has no refund API) |
| `EXPIRED` | the 5:00 payment session ended unpaid |

```
PENDING_PAYMENT → PAID | MANUAL_PENDING | EXPIRED | FAILED | CANCELLED
PAID            → PROCESSING | MANUAL_PENDING | FAILED | CANCELLED | REFUNDED
PROCESSING      → SUBMITTED | PAID (retry later) | FAILED | MANUAL_PENDING | CANCELLED
SUBMITTED       → IN_PROGRESS | COMPLETED | PARTIAL | CANCELLED | FAILED | REFUNDED
IN_PROGRESS     → COMPLETED | PARTIAL | CANCELLED | FAILED | REFUNDED
MANUAL_PENDING  → MANUAL_APPROVED | COMPLETED | CANCELLED | REFUNDED | FAILED
MANUAL_APPROVED → COMPLETED | REFUNDED | CANCELLED
COMPLETED / PARTIAL / CANCELLED → REFUNDED (and PARTIAL → COMPLETED)
FAILED          → PAID | PROCESSING | MANUAL_PENDING | REFUNDED | CANCELLED   (admin retry / move to manual)
EXPIRED         → PAID | MANUAL_PENDING                                       (a late payment can still land)
REFUNDED        → (final)
```
Every change goes through `Orders::transition()`, which validates the move under `SELECT … FOR UPDATE`, appends to `order_status_history` and marks the analytics day dirty. An illegal move answers `409`.

## 3. Payment sessions

* A session lives exactly **5:00** (`Orders::PAYMENT_TTL = 300`). The page counts down against the **server's** clock (`server_now` / `expires_at`), ticking once per second.
* At 00:00 the page shows **"Payment session expired"** and removes the QR; it never claims success locally. The server independently expires the payment (page poll, cron sweep) — but first **double-checks with the gateway**, so a payment made in the last seconds is not lost.
* **Late payments are honoured.** If the gateway later reports success for an expired session (money really arrived), it is credited once, flagged `late` (admin sees a "late payment" chip) and the page explains *"We received your payment after the payment session had ended, and it has been accepted."* A 2-minute grace window after expiry is not even flagged late (gateway callbacks lag).
* The QR image is served only while the session is live (`410` afterwards); an expired/paid/failed/under-review page ships no QR or pay link at all.
* **One payment session = one order.** A QR cannot buy a different order: the webhook is matched by gateway order id and the **amount must equal** the stored amount, otherwise the payment is flagged `mismatch` for manual review and the order is not fulfilled.
* **Duplicate protection:** the same visitor + service + link with a live unpaid session reuses it (double clicks, retries, two tabs, a response lost on a flaky connection); checkout is serialised per visitor/service/link with an advisory lock; every checkout carries an idempotency key. A second *payment* with a different bank reference is noted on the order so you can refund it.

## 4. AUTO orders (sent to the SMM provider)

`Fulfilment::submit()`:
1. **Claim:** `PAID → PROCESSING` inside a row-locked transaction. Only the winner of that claim calls the provider (verified with 6 parallel processes → exactly one provider order).
2. Call `add(service, link, quantity)` with the **order's snapshot** service id (never anything from the browser). The call is logged in `smm_transactions` (no key). This happens **after** the webhook has been answered: the payment gateway is never kept waiting for the provider (on PHP-FPM/LiteSpeed the connection is closed early; on plain mod_php/CGI the answer carries an exact `Content-Length`, so the gateway has read everything it will get while the work continues).
3. Provider returned an order id → `SUBMITTED`, store the id and the *estimated* cost.
4. **Cron syncs** status, `remains`, `start_count` and the provider's **actual charge** (converted with the provider's exchange rate) — the real charge replaces the estimate (`provider_cost_final = 1`). Provider "Canceled/Failed" means the provider refunded us → cost 0.

### What happens when the provider misbehaves
| Situation | Classified as | Result | Customer sees | Admin sees / can do |
|---|---|---|---|---|
| Server down, DNS failure, connection refused (**nothing was sent**) | `unreachable` | Order returns to `PAID`, retried automatically after 1, 5, 20, 60 min (5 attempts), then `FAILED` | "Payment received — starting your order" | reason + next attempt time; **Retry** any time |
| Provider says *Invalid API key*, *Incorrect service ID*, *Not enough funds*, *Incorrect link* | `rejected` | `FAILED` immediately (no blind retry) | "Needs attention — our team has been notified" | exact provider message; fix config/top up, **Retry** or **Move to manual** |
| Connection dropped *after* the request was sent, timeout, garbled/non-JSON answer, answer without an order id | `ambiguous` | `FAILED`, **never auto-retried** (the provider may already hold the order — retrying could double-buy) | same | "Unclear provider response … verify at the provider before retrying" |
| Service/provider became unusable after the customer paid | `misconfigured` | `FAILED` | same | fix in Admin → Services/SMM, **Retry** |
| An unexpected error while talking to the provider (anything the code did not foresee) | `ambiguous` | `FAILED` — an order that has been claimed is never left half-way | same | "…internal error (details are in the server log). Check the provider panel for this link before retrying" |
| The server process **dies** after claiming the order (host killed it, restart) — the order sits in `PROCESSING` | `ambiguous` | the cron **watchdog** hands any order stuck in `PROCESSING` for more than 10 minutes to a human as `FAILED` (never resubmitted automatically) | same | "The submission was interrupted … the provider may already have this order — check its panel (and the transaction log) for this link before retrying" |
| The provider **accepted** the order but the database refused to record the answer | `ambiguous` | as above after 10 minutes; the log line *"Provider accepted the order but it could not be recorded"* and the transaction log carry the provider's order id so you can match it | same | find the order id in Admin → Orders → drawer (provider transactions) and **Complete/Sync** it, or Move to manual |
| AUTO service has no SMM service id or no active provider *before* checkout | — | **Not sellable**: hidden from the store and refused at checkout (422) | card not shown | dashboard banner explains |

Customers never see provider names, messages, ids or costs; `GET /api/order/{token}` contains none of them.

## 5. MANUAL orders

Paid MANUAL orders enter the queue as `MANUAL_PENDING` (no provider call, no cost). In **Admin → Orders**:
* **Approve** (one order) or **APPROVE ALL** — confirmation *"Approve all eligible manual orders?"*, runs in **one transaction**, requires an idempotency key, only touches orders that are paid + MANUAL + pending; safe to repeat; two concurrent runs approve each order exactly once.
* **Complete**, **Cancel**, **Refund** (partial or full; recorded with your bank reference — revenue and status follow), **Note**.
* **Move to manual** takes a stuck AUTO order out of the provider flow.

## 6. Money bookkeeping

* **Revenue** = amount paid − refunds. **MANUAL** profit = revenue; **AUTO** profit = revenue − provider cost. **Net** = gross − advertising. See [PROFIT-AND-ANALYTICS.md](PROFIT-AND-ANALYTICS.md).
* A payment that arrives for an order that is no longer payable (cancelled/refunded…) is recorded and flagged for review, never silently ignored.
* `confirm_payment` (admin) settles a pending payment manually when you have verified the bank/UPI reference yourself — recorded in the audit log.
