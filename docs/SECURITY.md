# Security

This document describes what protects the shop, how each protection was **verified** (not assumed), the answers to the 13 questions of the final security audit, an operator checklist, and the residual risks you should know about.

> The audit is executable: `node tools/qa/security-audit.mjs` starts a production-like server (`APP_DEBUG=false`) and attacks it. Everything below marked ✔ is asserted by that script, by the PHP suite (`php tests/run.php`) or by the accessibility/Apache suites (see [QA-REPORT.md](QA-REPORT.md)).

## 1. Principles

1. **The browser is never trusted.** Price, quantity, mode, provider ids, payment and order status are decided on the server from the database. A client may only send a service id and a link.
2. **Secrets live on the server, encrypted, write-only.** Gateway key, webhook secret and SMM keys are AES-256-GCM encrypted at rest (key derived from `APP_KEY`), never returned by any API (only a masked hint), never logged, never in an error page.
3. **Fail closed, say little.** Errors are friendly and generic for visitors; details go to `storage/logs` (and, in local development only, to the response).
4. **Everything important is idempotent.** Checkout, Approve All, saves and the webhook are safe against double clicks, retries and parallel requests.
5. **Least privilege:** the DB user needs no `GRANT`/`SUPER`; uploads cannot execute; the web root can contain only `public/`.

## 2. Controls

### Authentication & sessions (admin)
* Passwords hashed with **Argon2id** (or **bcrypt-12 over a SHA-256 prehash** when the host's PHP lacks Argon2, which sidesteps bcrypt's 72-byte limit); hashes are upgraded transparently at the next sign-in; policy 12–128 chars with all four character classes, no common words or long runs; a generator is built in. Unknown users take the same time as wrong passwords (dummy hash) and get the same message.
* **Throttling:** 20 attempts / 15 min per IP, 8 / 15 min per account name, and **5 wrong passwords lock the account for 15 minutes**; both reset on success. Locked accounts can be unlocked from the CLI (`php bin/admin.php unlock`).
* **Sessions:** random 256-bit token in an `HttpOnly`, `SameSite=Lax` cookie (`__Host-` prefixed and `Secure` on https at the domain root); only its **SHA-256** is stored. Idle timeout 2 h (7 d with "remember me"), absolute lifetime 12 h (30 d); a new token is minted at every sign-in (no fixation); sign-out and password change revoke server-side, so a captured cookie is useless afterwards ✔. Admins can see and revoke sessions.
* A temporary password must be replaced before any other admin API works (`password_change_required`).

### Request forgery & origin checks
* **Admin API:** per-session HMAC **CSRF token** in the `X-CSRF-Token` header on every unsafe request, plus a same-origin check (`Origin: null` and foreign origins are refused) ✔.
* **Public JSON endpoints** require `Content-Type: application/json`, the `X-Requested-With: smb` marker and same-origin; the site sends **no CORS headers**, so no other website can call them from a browser ✔.
* The sign-in form uses a signed double-submit token cookie (works before a session exists).

### Browser hardening
* **Content-Security-Policy:** `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; manifest-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'` — **no inline scripts or style attributes anywhere** (the JavaScript applies styles through the CSSOM), no third-party origins, so an injected `<script>` or `onerror=` cannot run ✔.
* Also: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (camera, microphone, geolocation, payment, USB off), `Cross-Origin-Opener-Policy`/`-Resource-Policy: same-origin`, **HSTS** on https. Order, payment and admin pages are `no-store` + `noindex`.
* All output is HTML-escaped by a single helper; the admin UI renders through an auto-escaping template tag. Hostile order targets and notes are tested ✔.
* **Badge (tag) colours and names are admin-controlled data and are treated as such:** names are 2–24 characters of plain text (no `<` `>` or control characters), always HTML-escaped on the card; colours are accepted only as `#RGB` / `#RRGGBB` (stored as upper-case `#RRGGBB`) and are the only thing that ever reaches the generated `/tags.css`, which therefore cannot carry any other CSS (the generator re-validates every value). The tag endpoints sit behind the admin session, the same-origin check and the CSRF token, use prepared statements and are audit-logged ✔ (`tools/qa/admin-tags.mjs`, `tests/Integration/TagsTest.php`).

### Input handling
* **Links** are validated per platform *and* per service kind (profile / post / video / channel): generous about format (scheme, `www.`/`m.`, `@`, tracking parameters), strict about meaning (exact host match, no credentials, no ports, no `javascript:`, no look-alike hosts such as `instagram.com.evil.example`), control and zero-width characters stripped, 300-character cap. The browser and server implementations are verified against the same 124-case fixture file.
* **SQL:** PDO with native prepared statements and `utf8mb4` everywhere; no string-built SQL from user input.
* **Money:** parsed from strings into integer paise by a strict parser (no exponent, comma, Unicode digits, more than two decimals); the database columns are `UNSIGNED`, so a negative price or quantity cannot even be stored ✔.
* **Uploads** (logos, icons): size ≤ 2 MB, ≤ 16 MP, finfo MIME sniffing, PNG/JPG/WebP only (**SVG refused**), decoded and **re-encoded** with GD (drops metadata and any appended payload), random file names, stored in a folder where PHP execution is disabled (`.htaccess` + hostile-file tests on real Apache ✔). Only `index.php` may execute at the top of the web root, and `assets/` is inert too — a stray or planted `.php` file is answered `403` (while other applications in *sub-folders* of the same web root are left alone).

### Payments & webhooks
* Webhook signature = HMAC-SHA256 of the **raw body**, compared in constant time; forged events are stored as `invalid` (never consuming a real event id) and answered `401` ✔.
* Settlement under a **row lock**: amount must equal the amount we stored, the bank reference (UTR) must be unused, the payment must belong to that gateway; otherwise the payment is flagged `mismatch` and **nothing is fulfilled** ✔. Success is final: later `FAILED`/`EXPIRED` events cannot undo it ✔.
* The return URL (`?returned=1`) and every query-string flag are **not** proof of payment: the server asks the gateway ✔.
* **Duplicates:** an event id is processed once (20 concurrent deliveries → one settlement, one provider order) ✔; a second payment with a different bank reference is noted for refund.
* **Expired sessions:** no QR, no pay link, `qr.png` answers `410`; a new attempt always gets a fresh session ✔. (Money that genuinely arrives late is credited once and flagged — see [ORDERS.md](ORDERS.md).)

### SMM provider
* Called only from the server. The API key never leaves it, is never in `smm_transactions`, logs, responses or JS bundles ✔.
* The provider URL passes the **SSRF guard** (`NetGuard`): http(s) only, no credentials in the URL, DNS (A and AAAA) resolved and checked so private / loopback / link-local / carrier-grade-NAT / metadata addresses are refused unless `ALLOW_PRIVATE_API_HOSTS=true` (local mocks only); **redirects are never followed**, so a provider cannot bounce the server to an internal address.
* Orders are **claimed under a row lock** before the call; ambiguous outcomes are parked for a human instead of retried (no double-buying) ✔.

### Abuse protection
Database-backed rate limits (works on shared hosting, no Redis): checkout 8/10 min + 40/h per IP, lookups and polls per minute, beacons, webhook flood cap, admin sign-in. Order/payment tokens are 128-bit capabilities; guessing is rate-limited and tested ✔. Bots are excluded from analytics so they cannot generate ad cost.

### Logging, audit & privacy
* **Audit log** of every admin write (who, when, IP, what changed — **field names only**, never secret values) ✔.
* `webhook_events` and `smm_transactions` keep forensics without secrets ✔ (the audit greps the DB and log files for every secret).
* **Privacy-conscious analytics:** no IP, fingerprint or raw user-agent stored; cookies are first-party, `HttpOnly`, `SameSite=Lax`.

## 3. The 13-point final audit

Each answer below was *tested by attacking a running server*; the script prints one ✓ line per question.

| # | Question | Answer | How it is enforced | Test |
|---|---|---|---|---|
| 1 | Can a normal user access Admin? | **No** | Every `/admin/*` page redirects to sign-in; path tricks (`..`, `//`, `%2f`, case, `;`, trailing dot), `X-Original-URL`/`X-Forwarded-*` headers, forged cookies change nothing; the login page is `no-store`/`noindex` | Q1 |
| 2 | Can the user manipulate the price? | **No** | Checkout reads only `service_id` + link; amount/quantity/mode/provider id come from the DB row and are snapshotted; an old session keeps its price (a payment of a *new* price is flagged) | Q2, Q2b |
| 3 | Can the user fake payment success? | **No** | Only a correctly signed, amount-matching gateway event settles an order; unsigned / wrongly signed / tampered / replayed-for-another-order / short-paid events change nothing; return-URL flags are ignored | Q3 |
| 4 | Can the user change the service id? | **No** | Inactive, deleted, non-existent, platform-disabled and unroutable services are refused (422); the provider service id is never taken from the client; the link must fit the service kind | Q4 |
| 5 | Can the user access another order? | **No** | Orders/payments are reachable only by 128-bit tokens; public ids, numeric ids and case/suffix variations 404; lookup accepts tokens only (max 10); guessing is rate-limited; customer views contain no admin notes, errors or provider data | Q5 |
| 6 | Can the user call the SMM API directly? | **No** | Server-side only; no public route proxies it; no shipped file mentions the provider URL/key; admin test/search endpoints need a session | Q6 |
| 7 | Can the user expose API keys? | **No** | Encrypted at rest, write-only in the UI, absent from every public/admin response, page, header, error, log and audit row; `.env`/source/SQL/log paths are unreachable (also on real Apache in two layouts) | Q7, Apache |
| 8 | Can a duplicate webhook create a duplicate order? | **No** | Event-id dedupe + row-locked settlement + order claim: 20 concurrent deliveries → one payment, one `PAID`, one provider order | Q8 |
| 9 | Can a negative quantity be submitted? | **No** | Ignored by checkout; the admin API rejects negative/zero/fractional/huge/min>max values; the columns are `UNSIGNED` | Q9–Q11 |
| 10 | Can a negative price be submitted? | **No** | Same; price must be ₹1–₹1,00,000 with ≤ 2 decimals (27 malformed variants tested); unsellable rows are refused at checkout | Q9–Q11 |
| 11 | Can an invalid service be ordered? | **No** | See #4; also `price < ₹1`, `quantity` outside min/max, AUTO without SMM id/provider | Q4, Q9–Q11 |
| 12 | Can an expired payment be reused? | **No** | Expired sessions show no QR/link; `qr.png` → 410; a new checkout creates a new session; a signed late event is credited **once** and flagged; one QR cannot pay another order | Q12 |
| 13 | Can an unauthenticated user access admin APIs? | **No** | All 41 admin API routes answer `401` JSON to anonymous callers on every verb, with forged cookies/tokens too; signed in, missing/wrong CSRF or a foreign `Origin` → `403` | Q13 |

Additional hardening verified: headers on every response type, cookie flags, host-header injection (links come from `APP_URL`, never `Host`), open redirects, reflected XSS in paths/queries, stored XSS in service titles, TRACE/odd methods, oversized and deeply nested JSON, form-encoded bodies, cross-site beacons, rate limiting with `Retry-After`.

## 4. Operator checklist
See [DEPLOY-CPANEL.md §8](DEPLOY-CPANEL.md). In short: https + `APP_URL`, `APP_DEBUG=false`, strong unique admin password (and a second admin), gateway in Production with the webhook secret set, cron running, `.env` not world-readable, backups including **`APP_KEY`**.

## 5. Residual risks & honest limits
* **Capability URLs:** anyone holding an order/payment link can view that order (that is what makes accountless checkout possible). Links are 128-bit random, rate-limited, `no-store`, `noindex`, and carry no provider data — but treat them like a receipt.
* **Shared hosting** gives no isolation from other tenants on the same server; keep `.env` mode `640`, use unique DB credentials, prefer layout B.
* **Late-payment crediting** deliberately trusts a *correctly signed* gateway event that arrives after the session ended — the alternative is losing real customer money. They are flagged `late` for review.
* **Single admin role:** all admins are owners; there is no role separation. Give accounts only to people you trust fully.
* **Rate limits are per IP**; behind a proxy set `TRUSTED_PROXY_HEADER` or all visitors look like one address.
* The Alpha UPI and SMM integrations were verified against **protocol-faithful mocks**, not live accounts. Their security properties (signature checks, key handling, amount matching) are exercised end to end, but do a ₹5 test order in test mode before going live.
* Dependencies are vendored (Alpha UPI SDK) or compile-time only (Tailwind, esbuild, Lucide); there is no Composer/npm code on the production server, which keeps the supply chain small. Re-run `npm audit` before rebuilding assets.

## 6. Reporting a vulnerability
Email the shop owner / maintainer privately; do not open a public issue with exploit details. Include the URL, steps and the response you saw. Never test against someone else's shop.
