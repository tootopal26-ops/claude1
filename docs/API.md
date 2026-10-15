# HTTP API reference

Everything the browser or an external system can call. There are three audiences:

1. **Customers** (no account; capability URLs) — checkout, payment page, order tracking, analytics beacons.
2. **The payment gateway** — one signed webhook endpoint.
3. **The admin panel** — a JSON API behind a session cookie + CSRF token.

All JSON responses use one envelope:

```jsonc
// success                       // failure
{ "ok": true, … }                { "ok": false, "error": { "code": "service_unavailable", "message": "Human-readable, safe to show",
                                                           "fields": { "target": "…" },   // validation errors, when relevant
                                                           "retry_after": 42 } }          // rate limits (also the Retry-After header)
```
Error messages never contain stack traces, SQL, file paths, provider messages or secrets (with `APP_DEBUG=true` in local development a `debug` key is added — never in production).

The machine-readable route list is always available from the code: `php tools/qa/dump-routes.php` (used by the security audit).

---

## Conventions

| Topic | Rule |
|---|---|
| **Public POSTs** (`/api/checkout`, `/api/orders/lookup`) | `Content-Type: application/json`, header `X-Requested-With: smb`, same-origin (`Origin`/`Referer` host must equal `APP_URL`; an `Origin: null` is refused). Custom headers cannot be set cross-site without a CORS preflight, and no CORS headers are ever sent, so other sites cannot call these endpoints from a browser. |
| **Beacons** (`/api/track/*`) | Same-origin + JSON body (sent with `navigator.sendBeacon`, which cannot add headers). |
| **Admin API** | Session cookie (`__Host-smb_admin` on an https site at the domain root, `smb_admin` otherwise) **and** header `X-CSRF-Token: <token from <meta name="csrf-token">>` on every non-GET request, plus same-origin. Missing/invalid session → `401 {"error":{"code":"unauthenticated"}}`; bad token/origin → `403`. |
| **Idempotency** | `Idempotency-Key: <8–80 chars [A-Za-z0-9_.:-]>`. Required for checkout and Approve All; accepted on every admin save. The first request runs; identical repeats (same key + same body) replay the stored answer; a repeat while the first is still running waits ≤ 10 s then answers `409 in_progress`; the same key with a different body → `422 idempotency_mismatch`. Failed (4xx/5xx) attempts are not remembered, so a retry runs again. Keys live 24 h. |
| **Money** | Always integer **paise** in JSON (`amount_paise: 500` = ₹5.00); admin inputs accept rupee strings with ≤ 2 decimals (`"5.00"`). Percentages are integer tenths (`delta_tenths: -937` = −93.7 %). |
| **Time** | Timestamps are ISO-8601 UTC (`2026-10-14T18:45:32+00:00`); `*_day` fields are business-time-zone dates (`Asia/Kolkata` by default). Countdown values are epoch **milliseconds** (`expires_at`, `server_now`). |
| **Caching** | Order/payment pages and every API answer are `no-store`; payment/order pages are also `noindex`. |

### Rate limits (per client IP unless noted; fixed windows, MySQL-backed)
| Bucket | Limit |
|---|---|
| Checkout | 8 per 10 min and 40 per hour |
| Order page / order JSON / lookup | 90 / 120 / 30 per minute |
| Payment page / status poll / QR | 90 / 150 / 60 per minute |
| Webhook | 600 per 10 min |
| Beacons | hit 120 per 10 min · new visitors 40 per hour · qualify 60 per 10 min · ping 30 per 10 min per session |
| Admin sign-in | throttled per IP and per account; 5 wrong passwords lock the account for 15 minutes |

Exceeding a limit returns `429` with `Retry-After`.

---

## 1. Customer routes

| Method & path | Purpose |
|---|---|
| `GET /` | Storefront (server-rendered; every service and price is in the HTML). |
| `GET /theme.css` | The admin-chosen accent colours as CSS variables (validated `#rrggbb` + contrast-checked). |
| `GET /manifest.webmanifest` · `/robots.txt` · `/sitemap.xml` · `/health` | Web manifest, crawler files, liveness probe (`200 {"ok":true}`; `503` when the database is unreachable). |
| `POST /api/checkout` | Create a pending order + payment session. See below. |
| `GET /pay/{ref}` | The **SCAN AND PAY** page. `{ref}` is 32 hex chars (128-bit capability). `?returned=1` (set by the gateway redirect) makes the server verify with the gateway before rendering. |
| `GET /pay/{ref}/qr.png` | The QR image — **only while the session is live** (`410` when expired / paid / failed). `?download=1` adds `Content-Disposition: attachment; filename="SMB-XXXXXXXX-payment-qr.png"`. |
| `GET /api/pay/{ref}/status` | Poll endpoint: `{ok, state: pending\|paid\|expired\|failed\|review, payment_status, order_status, order_label, expires_at, server_now, late}`. Verifies with the gateway at most once per few seconds. |
| `GET /order/{token}` · `GET /api/order/{token}` | Tracking page / JSON: `{ok, order:{order_id, service, platform, quantity, amount, target, status, status_label, paid, done, created_at, steps:[{label, at}]}}`. No provider ids, costs, notes or errors. |
| `POST /api/orders/lookup` | `{ "tokens": ["<32 hex>", …] }` (max 10 considered) → `{ok, orders:[…same as above + track_url, token, pay_url]}` — powers the Shop drawer from the tokens kept in the browser. |
| `POST /api/track/hit` | `{path, first, ref, utm_source, utm_medium, utm_campaign}` → `{ok, tracked, qualified, qualify_ms}`; sets the first-party cookies `smb_v` (visitor, 400 days) and `smb_s` (session). Bots are answered `{"tracked":false,"reason":"bot"}` and never counted. |
| `POST /api/track/qualify` | Sent after the visitor has been **visible** for `qualify_ms`; the server re-checks the clock → `{ok, qualified, charged}` or `{qualified:false, retry_ms}`. One CPC per visitor per business day. |
| `POST /api/track/ping` | `{active_s, platform}` heartbeat on tab hide / unload. |
| `GET\|POST /cron/run?key=<CRON_KEY>` | Runs the background tasks (HTTP cron). `403` without the right key. |
| `GET /install` … | The web installer (token-gated). **404 once installed.** |

### `POST /api/checkout`

```http
POST /api/checkout
Content-Type: application/json
X-Requested-With: smb
Idempotency-Key: 6f1c0e0a-5a54-4b1e-9a77-3b6b0a3f2d10

{ "service_id": 3, "target": "https://www.instagram.com/p/AbC123xyz/" }
```
```jsonc
200  { "ok": true, "ref": "9c3e…(32 hex)", "order_id": "SMB-7KQ2M9XA", "url": "/pay/9c3e…", "token": "e41d…(32 hex)" }
```
Only `service_id` and `target` are read. **Everything else a client sends (price, amount, quantity, mode, provider ids, status…) is ignored** — the order is built from the database row. The link is validated for the service's platform *and* kind (profile / post / video / channel) and normalised (`@name`, `instagram.com/name`, tracking parameters, `m.`/`www.` hosts are all accepted; foreign hosts, credentials in the URL, `javascript:` and control characters are not).

Errors: `422 invalid` / `invalid_target` (with `fields.target`) / `service_unavailable` (inactive, deleted, platform off, price < ₹1, quantity outside min/max, or an AUTO service that cannot be routed) · `429 rate_limited` · `503 payments_unavailable` (gateway down/refused — the half-created order is closed, nothing is left pending) · `503 maintenance` · `409 in_progress`.

If the same visitor already has a live, unpaid session for the **same service and link**, that session is returned instead of creating another (no duplicate orders from double clicks, retries or two tabs).

---

## 2. Payment webhook

`POST /webhooks/payments/{gatewayId}` — configure `https://your-domain/webhooks/payments/1` in the Alpha UPI dashboard.

```http
POST /webhooks/payments/1
Content-Type: application/json
X-Alpha-Signature: <hex HMAC-SHA256 of the RAW request body, key = the webhook secret>

{ "event_id": "evt_9f2c", "order_id": "SMB9F3A…", "status": "SUCCESS", "amount": "5.00", "utr": "UTR8A12", "provider": "bharatpe", "paid_at": "2026-10-14 12:00:00" }
```
Pipeline: verify the signature (constant-time) → de-duplicate by event id → find the payment by gateway order id **and** gateway → settle under a row lock: the amount must equal the stored amount, the UTR must be unused → order becomes PAID and is routed. AUTO orders are submitted to the SMM provider **after** the HTTP answer is sent (the answer is complete before the provider is contacted, whatever PHP handler the host uses, so a slow provider never makes the gateway's webhook time out).

| Answer | Meaning |
|---|---|
| `200 ok` | processed, or a signed event for an unknown order (retrying cannot help) |
| `200 duplicate` | this event id was already handled — acknowledge so the gateway stops retrying |
| `400 invalid payload` | correctly signed but malformed |
| `401 invalid signature` | stored as an `invalid` webhook event (visible in Admin → Payments); nothing else happens |
| `404` | unknown gateway id |

`SUCCESS` with a different amount → the payment is flagged **mismatch** for a human and the order is *not* fulfilled. `FAILED` / `EXPIRED` events can never undo a successful payment. A second payment with a *different* bank reference for an already-paid order is noted on the order ("Duplicate payment received … refund the duplicate").

---

## 3. Admin routes

HTML shells (`GET /admin`, `/admin/login`, `/admin/{dashboard|orders|payments|services|website|advertising|gateway|smm|security|audit|system}`) redirect to the sign-in page when signed out. The pages load their data from the JSON API below.

### Auth
| Method & path | Purpose |
|---|---|
| `POST /admin/login` | `{username, password, remember?}` + signed pre-login CSRF token → session cookie. Generic error for unknown user and wrong password alike; dummy-hash timing equalisation. |
| `POST /admin/logout` | Revokes the session server-side (the cookie is useless afterwards). |

### Dashboard
| | |
|---|---|
| `GET /admin/api/dashboard?range=today\|7d\|30d\|90d\|lifetime\|custom&from=YYYY-MM-DD&to=YYYY-MM-DD&platform=&mode=&payment=` (default `7d`; custom ranges are capped) | Everything the dashboard shows: `meta` (range, time zone, `cpc_paise`, `qualify_seconds`), `kpis.today/lifetime` (14 KPIs with previous-period deltas), `breakdown` (revenue − SMM − ads = net, margin), `series` (chart data), `visitors`, `performance`, `smm`, `payments`, `recent`. Filtered views show profit **before** ads (ads are site-wide: `meta.ads_attributable=false`). |
| `GET /admin/api/status` | Header badge + setup notices: approvable count, gateway/SMM/cron/password status, pending migrations. |

### Orders & payments
| | |
|---|---|
| `GET /admin/api/orders?filter=&status=&mode=&platform=&from=&to=&q=&page=&per=` | Paged list (`per` 10–100, default 25) with chip counts. |
| `GET /admin/api/orders/{id}` | Full detail: customer view, money, payment, provider data, timeline, allowed actions. |
| `POST /admin/api/orders/{id}/action` | `{action, note?, amount?, reference?}` — `approve` · `complete` · `cancel` · `retry_submit` · `move_to_manual` · `sync` · `refund` (`amount` in ₹, `reference`) · `confirm_payment` (`reference` = the bank/UPI reference you verified) · `note`. Illegal transitions answer `409`. |
| `GET /admin/api/orders/approvable` · `POST /admin/api/orders/approve-all` | Count of eligible manual orders · approve them all in one transaction. Body `{"confirm": true}` **and** an `Idempotency-Key` are required. |
| `GET /admin/api/payments?status=&q=&page=` | Payments + the latest webhook events. |

### Catalogue
| | |
|---|---|
| `GET /admin/api/services` · `GET /admin/api/services/meta` · `GET /admin/api/services/{id}` | List (grouped by platform, with profit/order), form metadata, one service. |
| `POST /admin/api/services` · `PUT /admin/api/services/{id}` · `DELETE /admin/api/services/{id}` | Create / update / soft-delete. Fields: `code, platform_id, type, target_kind, title, subtitle, description, price (₹ string), quantity, min_quantity, max_quantity, mode (AUTO\|MANUAL), smm_provider_id, smm_service_id, smm_cost (₹), badge, badge_color, is_premium, status (active\|inactive), sort_order, icon_path`. Price must be ₹1–₹1,00,000, quantities ≥ 1, `min ≤ quantity ≤ max`; AUTO needs `smm_service_id`. Violations → `422` with `fields`. `badge` is an *extra* label: the red **TRIAL PACK** badge is derived from the price (every service sold at exactly ₹5), so that text is reserved — typing it is refused with `422` / `fields.badge`. Service objects carry `trial_pack` (true at ₹5) and `meta` carries `trial_pack {label, price_paise, price}`. |
| `POST /admin/api/services/icon` | Multipart icon upload (PNG/JPG/WebP ≤ 2 MB, re-encoded) → `{path}`. |
| `PATCH /admin/api/platforms/{id}` · `POST\|DELETE /admin/api/platforms/{id}/logo` | Platform name/tagline/visibility/order · upload or reset the platform logo. |

### Settings
| | |
|---|---|
| `GET\|PUT /admin/api/website` | Site name, tagline, accent colours, SEO, support link, maintenance mode + message, payment instructions, business time zone (locked once orders exist). |
| `POST\|DELETE /admin/api/branding/{site_logo\|favicon}` | Upload / reset the logo or favicon. |
| `GET\|PUT /admin/api/advertising` | CPC (₹) and qualification seconds; history is append-only; new values apply to *new* visitors only. |
| `GET /admin/api/gateway` · `PUT /admin/api/gateway/{id}` · `POST /admin/api/gateway/{id}/test` | Gateway config (`environment`, `base_url`, `api_key`, `webhook_secret`, active flag). Secrets are **write-only**: responses contain only `configured: true` + a masked hint. |
| `GET\|POST /admin/api/smm` · `PUT\|DELETE /admin/api/smm/{id}` · `POST /admin/api/smm/{id}/test` · `GET /admin/api/smm/{id}/services?q=` | Providers (name, URL, key, currency, ₹ rate, default/active), connection test (balance), provider service search for the service editor. Provider URLs are checked by the SSRF guard (https, public addresses, no credentials). |

### Account, audit, system
| | |
|---|---|
| `POST /admin/api/account/password` | `{current, new, confirm}` — policy: 12–128 characters with a lowercase, uppercase, digit and symbol; no common words, no long runs of one character; revokes the other sessions. |
| `GET /admin/api/account/sessions` · `DELETE /admin/api/account/sessions/{id}` · `POST /admin/api/account/sessions/revoke-others` | Active sessions (IP, browser, last seen). |
| `GET /admin/api/audit?group=&q=&page=` | Audit log (who/what/when; field **names** only — never values of secrets). |
| `GET /admin/api/system` · `POST /admin/api/system/cron` · `POST /admin/api/system/migrate` | PHP/DB/extension/folder health, cron age + command, queues, pending database updates · run the background tasks now · apply pending database updates after uploading a newer release (same as `php bin/migrate.php`; serialised with a lock, idempotent, rate-limited, audited as `system.migrated`). |

> A signed-in admin who still has the temporary password (`must_change_password`) is limited to `account/password`, `account/sessions`, `status` and `logout` (`403 password_change_required` elsewhere) until it is changed; the admin pages redirect to **Security**.
