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
| `GET /tags.css` | One rule per **enabled** tag, `.tag-<id>{--tag-bg:#RRGGBB;--tag-fg:#RRGGBB;--tag-ts:…}` — the badge colours (the CSP forbids inline styles, so they live here). Every value is a validated HEX colour; the link carries `?v=<hash>` so a change shows at once. |
| `GET /manifest.webmanifest` · `/robots.txt` · `/sitemap.xml` · `/health` | Web manifest, crawler files, liveness probe (`200 {"ok":true}`; `503` when the database is unreachable). |
| `POST /api/checkout` | Create a pending order + payment session. See below. |
| `GET /pay/{ref}` | The payment page: **PAY SECURELY → SCAN AND PAY** — the order's QR, **DOWNLOAD QR CODE** and the 05:00 timer — when a validated UPI payment request for exactly this order is stored; otherwise (only reachable from *Your orders* or the gateway's return) the slim **COMPLETE YOUR PAYMENT** card with one **CONTINUE TO PAYMENT** button. See [ORDERS.md §3a](ORDERS.md). `{ref}` is 32 hex chars (128-bit capability). `?returned=1` (set by the gateway redirect) makes the server verify with the gateway before rendering. |
| `GET /pay/{ref}/qr.png` | The QR image of the order's **UPI payment request** — only while the session is live **and** a valid `upi://pay` request for exactly this amount is stored (`410` otherwise: expired / paid / failed, or a payment that has no request). `?download=1` adds `Content-Disposition: attachment; filename="SMB-XXXXXXXX-payment-qr.png"` (the order id). The gateway's hosted https URL is never encoded. |
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
`url` is where the browser goes next: **our payment page** (`/pay/{ref}`) when the order has a QR to show, or — when the gateway's checkout page carried no usable UPI request — **the gateway's own checkout page** (an absolute `https://…` address, the documented "redirect your customer to `payment_url`"). See [ORDERS.md §3a](ORDERS.md).
Only `service_id` and `target` are read. **Everything else a client sends (price, amount, quantity, mode, provider ids, status…) is ignored** — the order is built from the database row. The link is validated for the service's platform *and* kind (profile / post / video / channel) and normalised (`@name`, `instagram.com/name`, tracking parameters, `m.`/`www.` hosts are all accepted; foreign hosts, credentials in the URL, `javascript:` and control characters are not).

Errors: `422 invalid` / `invalid_target` (with `fields.target`) / `service_unavailable` (inactive, deleted, platform off, price < ₹1, quantity outside min/max, or an AUTO service that cannot be routed) · `429 rate_limited` · `503 payments_unavailable` (gateway down/refused — the half-created order is closed, nothing is left pending) · `503 maintenance` · `409 in_progress`.

If the same visitor already has a live, unpaid session for the **same service and link**, that session is returned instead of creating another (no duplicate orders from double clicks, retries or two tabs).

---

## 2. Payment webhook

`POST /webhooks/payments/{gatewayId}` — configure `https://your-domain/webhooks/payments/1` in the Alpha UPI dashboard (Webhooks portal).

```http
POST /webhooks/payments/1
Content-Type: application/json
X-PayIndia-Signature: <hex HMAC-SHA256 of the RAW request body, key = the webhook secret>

{ "event": "payment.success", "status": "success", "order_id": "SMB9F3A…", "amount": 5.00, "utr": "615372849102" }
```
The signature may arrive in **any header whose name contains "signature"** (`X-PayIndia-Signature`, `X-Alpha-Signature`, `X-Hub-Signature-256` …) — the digest, not the header's name, authenticates the delivery, and it must be the exact lower-case hex digest (no `sha256=` prefix, no other case). The body may be the current format above, the older one — `{ "event_id": "evt_9f2c", "order_id": "…", "status": "SUCCESS", "amount": "5.00", "utr": "…", "provider": "bharatpe" }` — or either nested under `data`. Only unmistakable words count as paid (`success`, `successful`, `paid`, `completed`, any case); unfamiliar statuses change nothing.
Pipeline: verify the signature (constant-time) → de-duplicate by event id (the current payload has none, so a SHA-256 of the body stands in: identical redeliveries are acknowledged as `duplicate`, and settlement itself is idempotent) → find the payment by gateway order id **and** gateway → settle under a row lock: the amount must equal the stored amount, the UTR must be unused → order becomes PAID and is routed. AUTO orders are submitted to the SMM provider **after** the HTTP answer is sent (the answer is complete before the provider is contacted, whatever PHP handler the host uses, so a slow provider never makes the gateway's webhook time out).

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
| `POST /admin/api/services` · `PUT /admin/api/services/{id}` · `DELETE /admin/api/services/{id}` | Create / update / soft-delete. Fields: `code, platform_id, type, target_kind, title, subtitle, description, price (₹ string), quantity, min_quantity, max_quantity, mode (AUTO\|MANUAL), smm_provider_id, smm_service_id, smm_cost (₹), tag_id, is_premium, status (active\|inactive), sort_order, icon_path`. Price must be ₹1–₹1,00,000, quantities ≥ 1, `min ≤ quantity ≤ max`; AUTO needs `smm_service_id`. Violations → `422` with `fields`. `tag_id` is the badge shown on the card: an id from `GET /admin/api/tags`, or `null` / `0` / `""` for no tag (omit it on a partial update to leave the tag alone). The automatic **TRIAL PACK** tag cannot be assigned (`422` / `fields.tag_id`) — it is derived from the price (see below) — and a disabled tag cannot be newly assigned, but a service that already has one keeps it. Service objects carry `tag_id` (the assignment) and `effective_tag` (`{id, name, bg, fg, auto}` — what the storefront really shows: the enabled TRIAL PACK tag when `price_paise` is exactly 500, else the assigned tag if enabled, else `null`). `meta` carries `tags` (every tag: `id, name, bg_color, text_color, is_active, is_system, is_auto`) and `tag_rule {code, tag_id, price_paise, price}`. |
| `POST /admin/api/services/icon` | Multipart icon upload (PNG/JPG/WebP ≤ 2 MB, re-encoded) → `{path}`. |
| `PATCH /admin/api/platforms/{id}` · `POST\|DELETE /admin/api/platforms/{id}/logo` | Platform name/tagline/visibility/order · upload or reset the platform logo. |

### Tags & Badges
| | |
|---|---|
| `GET /admin/api/tags` | `{tags, meta}` — every tag in display order (`id, code, name, bg_color, text_color, is_active, is_system, is_auto, usage, default, contrast, sort_order`; `usage` = live services using it, `default` = the factory name/colours of a predefined tag, `contrast` = text-on-background ratio) and the rules (`meta.trial`, `meta.limits`, `meta.defaults`). |
| `POST /admin/api/tags` | Create a custom tag. Body `{name, bg_color, text_color?, is_active?}` (text defaults to white, enabled to true). → `{tag}`. |
| `PUT /admin/api/tags/{id}` | Partial update: send only what changes (`name`, `bg_color`, `text_color`, `is_active`). Predefined tags can be edited and disabled too. → `{tag}`. |
| `DELETE /admin/api/tags/{id}` | Delete a **custom** tag; the services that used it have no tag afterwards (`unassigned` = how many). Predefined tags → `422` / `code: "protected"` (disable them instead). |

Validation (server-side, `422` with `fields`): **name** 2–24 characters after collapsing whitespace, at least one letter or digit, no `<` `>` or control characters, unique case-insensitively (also against the predefined tags); **colours** `#RGB` or `#RRGGBB` only (stored as upper-case `#RRGGBB`) — no names, `rgb()`, alpha or anything that could carry CSS; text on background must reach a contrast ratio of 2:1 (the editor warns below the WCAG 4.5:1 AA level but lets you keep the colours); at most 100 tags. All four routes sit behind the admin session, the same-origin check and the CSRF token, use prepared statements and write an audit-log entry (`tag.created`, `tag.updated`, `tag.deleted`). A concurrent double-click on Save creates one tag (idempotency key).

**What a card shows** (`App\Services\Tags::effective`) — never more than one badge: the enabled TRIAL PACK tag if the selling price is exactly **500 paise** (₹5.00, integer comparison — ₹4.99 and ₹5.01 are not ₹5); otherwise the service's assigned tag if that tag is enabled; otherwise nothing. Disabling a tag hides it without touching assignments; deleting a custom tag clears them; moving a price away from ₹5 brings the assigned tag back on the next page load.

### Settings
| | |
|---|---|
| `GET\|PUT /admin/api/website` | Site name, tagline, accent colours, SEO, support link, maintenance mode + message, payment instructions, business time zone (locked once orders exist). |
| `POST\|DELETE /admin/api/branding/{site_logo\|favicon}` | Upload / reset the logo or favicon. |
| `GET\|PUT /admin/api/advertising` | CPC (₹) and qualification seconds; history is append-only; new values apply to *new* visitors only. |
| `GET /admin/api/gateway` · `PUT /admin/api/gateway/{id}` · `POST /admin/api/gateway/{id}/test` · `POST /admin/api/gateway/{id}/qr-check` | Gateway config: `environment`, `qr_from_checkout_page` (default on: read the UPI request from the gateway's checkout page so the QR is on our page), `base_url` (the API host, e.g. `https://alphaupi.site`; a trailing `/api` is trimmed), `api_style` (`headers` = the current Alpha UPI API with `X-API-Key` + `X-API-Secret`, the default; `bearer` = the older single-key SDK API), `provider_pref` (older API only), `api_key`, `api_secret` (current API only), `webhook_secret`, active flag. Going live needs every secret the chosen type uses. Secrets are **write-only**: responses contain only `has_… : true` + a masked hint. The test is a read-only lookup of an order that cannot exist and answers `ok` / `warning` (reachable, credentials not refused) / `error` (rejected key or secret, an address that is not the API, unreachable). **`qr-check`** (idempotency key honoured, 6 per 10 minutes per admin) creates one ₹1 test order at the gateway — never paid, never one of your orders — and answers `{result: {ok, found, level, message, details, checkout_url, duration_ms}}`: `found: true` when its checkout page carried a usable UPI request, otherwise the reason in words (`details.reason`, HTTP status, size, number of UPI links seen). With the setting off no test order is made. |
| `GET\|POST /admin/api/smm` · `PUT\|DELETE /admin/api/smm/{id}` · `POST /admin/api/smm/{id}/test` · `GET /admin/api/smm/{id}/services?q=` | Providers (name, URL, key, default/active — ₹ only, no currency or rate), connection test (balance; says so when the panel bills in another currency), provider service search for the service editor (cost estimate = rate per 1,000 × quantity, in ₹). Provider URLs are checked by the SSRF guard (https, public addresses, no credentials). |

### Account, audit, system
| | |
|---|---|
| `POST /admin/api/account/password` | `{current, new, confirm}` — policy: 12–128 characters with a lowercase, uppercase, digit and symbol; no common words, no long runs of one character; revokes the other sessions. |
| `GET /admin/api/account/sessions` · `DELETE /admin/api/account/sessions/{id}` · `POST /admin/api/account/sessions/revoke-others` | Active sessions (IP, browser, last seen). |
| `GET /admin/api/audit?group=&q=&page=` | Audit log (who/what/when; field **names** only — never values of secrets). |
| `GET /admin/api/system` · `POST /admin/api/system/cron` · `POST /admin/api/system/migrate` | PHP/DB/extension/folder health, cron age + command, queues, pending database updates · run the background tasks now · apply pending database updates after uploading a newer release (same as `php bin/migrate.php`; serialised with a lock, idempotent, rate-limited, audited as `system.migrated`). |

> A signed-in admin who still has the temporary password (`must_change_password`) is limited to `account/password`, `account/sessions`, `status` and `logout` (`403 password_change_required` elsewhere) until it is changed; the admin pages redirect to **Security**.
