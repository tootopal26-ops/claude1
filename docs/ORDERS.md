# Orders & payments: lifecycle, routing and failure handling

## 1. The journey of one order

```
 customer                    server                                         gateway / provider
 ────────                    ──────                                         ──────────────────
 BUY NOW → link ✓ valid
 PROCEED ───────────────▶ POST /api/checkout
                            • price, quantity, mode come from the DB
                            • order PENDING_PAYMENT + payment "creating"
                            • ask the gateway for a payment ─────────────▶ create-order
                            • payment "pending", expires in 5:00 ◀───────── checkout URL (payment_url)
                            • read the UPI request on that checkout page ─▶ GET payment_url (no credentials)
                              (best effort, validated: exactly this order, this amount, INR)
 ◀── /pay/{ref} ──────────  PAY SECURELY → SCAN AND PAY: the QR, DOWNLOAD QR CODE, 05:00 countdown
 ◀── (no UPI request found: straight to the gateway's own checkout page, same tab)
 scans / opens with any UPI app ──────────────────────────────────────────▶ bank
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
* At 00:00 the page shows **"Payment session expired"** and removes the QR / the pay button; it never claims success locally. The server independently expires the payment (page poll, cron sweep) — but first **double-checks with the gateway**, so a payment made in the last seconds is not lost.
* **Late payments are honoured.** If the gateway later reports success for an expired session (money really arrived), it is credited once, flagged `late` (admin sees a "late payment" chip) and the page explains *"We received your payment after the payment session had ended, and it has been accepted."* A 2-minute grace window after expiry is not even flagged late (gateway callbacks lag).
* The QR image (§3a) is served only while the session is live (`410` afterwards); an expired/paid/failed/under-review page ships no QR or pay link at all.
* **One payment session = one order.** A payment request cannot buy a different order: the webhook is matched by gateway order id and the **amount must equal** the stored amount (and the gateway must not report a currency other than INR), otherwise the payment is flagged `mismatch` for manual review and the order is not fulfilled.
* **Duplicate protection:** the same visitor + service + link with a live unpaid session reuses it (double clicks, retries, two tabs, a response lost on a flaky connection); checkout is serialised per visitor/service/link with an advisory lock; every checkout carries an idempotency key. A second *payment* with a different bank reference is noted on the order so you can refund it.

## 3a. The payment page: the real QR on your page

What the customer sees depends on what we **actually obtained** for the order (`payments.qr_payload`, `payments.payment_url`):

| What we have | The customer goes to | The card shows |
|---|---|---|
| a validated UPI payment request for exactly this order (`qr_payload`) | **our page**, `/pay/{ref}` | header **SOCIAL MEDIA BOOSTER** → **SECURE PAYMENT** → **PAY SECURELY** → `₹5 — 50 Followers` → one centred white card: **SCAN AND PAY**, **the QR** (drawn by our backend, `GET /pay/{ref}/qr.png`, shown up to 20 rem wide so another phone can scan it), the payee, **DOWNLOAD QR CODE**, **TIME LEFT TO PAY 05:00**. Phones also get **OPEN UPI APP** (a phone cannot scan its own screen). No second page, no instruction card. |
| no usable UPI request | **the gateway's own checkout page**, straight from PROCEED, in the same tab (the documented "redirect your customer to `payment_url`") | nothing of ours stands in the way. If the customer later opens our `/pay/{ref}` (Your orders, or the return from the gateway) they see the slim card **COMPLETE YOUR PAYMENT** with the timer and one **CONTINUE TO PAYMENT** button. |

### Where the QR comes from — and what is not guaranteed

**What Alpha UPI documents.** `POST /api/create-order` answers `data.payment_url` (a hosted checkout page), `token`, `amount`, `currency`, `expires_at`; `POST /api/check-status` answers the payment state; the webhook is HMAC-SHA256 signed. The bundled SDK's `createOrder` returns the `payment_url` only. There is **no QR image URL, no Base64 QR, no QR payload string and no UPI URI** in the documentation or the SDK — the gateway offers a hosted checkout URL and nothing else.

**What was wrong.** The earlier page encoded that https URL in its QR: a UPI app cannot pay it and a camera opens it as a web page that draws *another* QR. The version after it removed the QR altogether and showed an instruction card (open another page, scan a second QR) — also wrong. A QR of the hosted URL is never drawn, and the instruction card is gone.

**What it does now.** `App\Payments\HostedCheckout` opens the gateway's own documented `payment_url` **on the server** and looks in that page for the `upi://pay?…` request its QR is made of — in a link, in JSON / a script, URL-encoded inside an image address, or as an app's own `phonepe://` / `tez://` / `paytmmp://` … link (read back as the same `upi://pay` request). It accepts a request **only** if `App\Support\Upi::validateFor()` passes for *this payment's own amount*: valid payee address, `am` equal to the order amount, currency INR (or absent), known parameters only (a vendor extra such as Paytm's `paytmqr` is kept if it is a short token), none repeated, URL-safe characters only. Anything else — another amount, another currency, a web link in a parameter, no amount — is dropped and the fallback row above applies. Safeguards: only the gateway's own host (or a sub-domain) is fetched, with the SSRF guard and an IP pin; **no API key, secret, cookie or authorization header is sent**; 3 s connect / 5 s total, 600 KB cap, no redirects; a down, slow or enormous page just means "no QR" and never delays or fails the payment.

**What cannot be promised.** Reading a QR out of a gateway's checkout page is **not a documented gateway API**; it works only while that page carries the UPI request in a form we can read, and it can stop working if the gateway changes its page. The real `alphaupi.site` page could not be reached from the machine this was built on, so the behaviour against the live gateway is **untested**; the tests use a protocol-faithful mock whose page carries the request in several forms (and a no-request page for the fallback). To get evidence from the real gateway: **Admin → Payment gateway → Check QR support** creates one ₹1 test order (never paid, not one of your orders) and says in words whether its checkout page carried a usable request — and why not, if it did not. If it says *not found*, customers are sent to the gateway's own checkout page, which shows the gateway's QR there; for a QR on your own page you need a gateway (or an Alpha UPI plan) whose API returns the UPI request — ask Alpha UPI support, or add an adapter with `Gateways::register()` that sets `CreatePaymentResult::$upiIntent`. The switch **Show the gateway's QR on my payment page** (same screen, stored as `qr_from_checkout_page`) turns the lookup off for good if you ever want customers to go straight to the gateway.

**One QR per order.** The request is created by the gateway for this order (its reference is in the request), stored with the payment row under the order's own `ref`, and never shared: another order gets another request. The page and `qr.png` re-validate the stored request against the payment's own amount on **every view and every image request**, so a changed row can never put a QR for another amount on screen. Payments created before this change (their `qr_payload` is the hosted https URL) are shown as the slim "continue" card.

**Download.** `GET /pay/{ref}/qr.png?download=1` returns the very PNG the page shows, as `SMB-XXXXXXXX-payment-qr.png` (the order id), with `Content-Disposition: attachment` — not a screenshot of the page.

**Paying is confirmed only by the server**, in every mode: the signed webhook, or the server asking the gateway (`check-status`) when the page polls or the customer returns (`?returned=1`). There is no "I have paid" button, and a view, a scan, a download or a click changes nothing. The amount must equal the stored amount and the currency must be INR (§3), the bank reference (UTR) is used once, a repeated webhook is a no-op, an unknown order is never assumed paid, and a gateway "failed" or an expired session shows the failed / expired page. The 05:00 countdown is computed from the server's clock, shown under the QR, and stops being meaningful the moment the server says the session is over.

## 4. AUTO orders (sent to the SMM provider)

`Fulfilment::submit()`:
1. **Claim:** `PAID → PROCESSING` inside a row-locked transaction. Only the winner of that claim calls the provider (verified with 6 parallel processes → exactly one provider order).
2. Call `add(service, link, quantity)` with the **order's snapshot** service id (never anything from the browser). The call is logged in `smm_transactions` (no key). This happens **after** the webhook has been answered: the payment gateway is never kept waiting for the provider (on PHP-FPM/LiteSpeed the connection is closed early; on plain mod_php/CGI the answer carries an exact `Content-Length`, so the gateway has read everything it will get while the work continues).
3. Provider returned an order id → `SUBMITTED`, store the id and the *estimated* cost.
4. **Cron syncs** status, `remains`, `start_count` and the provider's **actual charge** — read as ₹ (the shop is INR-only; a charge the panel declares in another currency is ignored and the ₹ estimate stays) — the real charge replaces the estimate (`provider_cost_final = 1`). Provider "Canceled/Failed" means the provider refunded us → cost 0.

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
