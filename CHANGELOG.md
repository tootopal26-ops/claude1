# Changelog

## Account links, INR only, and the payment QR (this release)

**Upgrading:** upload the files, then **Admin → System → Apply database updates** (or `php bin/migrate.php`) to apply migration `004` — it only replaces the *old default* payment-page instructions text, never a text you wrote. Rebuilding assets is not needed (the compiled CSS/JS is included).

### Followers and Subscribers ask for the account link
* **Instagram Followers** → label **Instagram Profile Link**, placeholder `https://www.instagram.com/username/`. **YouTube Subscribers** → **YouTube Channel Link**, placeholder `https://www.youtube.com/@channelname`.
* No username or `@handle` is asked or accepted for these two. Accepted: `instagram.com/<name>` (with or without `https://`, `www.`, `m.`, a trailing slash, tracking parameters, `/_u/<name>`); `youtube.com/@handle`, `/channel/UC…`, `/c/<name>`, `/user/<name>`, a legacy `/<name>`. Refused: other domains and look-alikes (`instagram.com.evil.com`, credentials, odd ports, `ftp:`/`javascript:`), post / video links, the site root, malformed names.
* Validated live in the browser **and** again on the server (the server's answer is the one that counts; both run the same 219 fixture cases). The order keeps the complete validated link; the SMM panel receives it; the admin Orders list, drawer and **Manual delivery** section show it with *open* and *copy*; the customer's tracking page and "Your orders" drawer show it; the order history line carries it.
* Blue Tick, likes, views and watch time keep their current inputs.

### INR only
* The SMM provider form no longer has a currency (it defaulted to **USD** and offered INR / USD / EUR / GBP) or an exchange rate. A provider's charge is read as rupees; a charge a panel declares in another currency is **not converted** — the ₹ cost you enter on the service stays in force — and *Test connection* says when a panel bills in another currency. The dashboard's dollar-sign icon is now a rupee badge.
* A gateway that opens an order in another currency is refused; a success it reports in another currency is held for review and never fulfilled. Amounts are sent to the gateway as rupees with two decimals (`5.00`).
* Database defaults were already INR; nothing in the database is rewritten (the two legacy provider columns are set to `INR` / `1.0` only when you save that provider).

### Payment QR
* **Root cause:** the QR encoded the gateway's *hosted checkout URL*. A UPI app cannot pay an https URL and a camera opens it as a web page that draws another QR. The documented Alpha UPI API (and the bundled SDK) returns only that URL — no UPI request, QR string or image.
* The payment page now draws a QR **only** for a real `upi://pay` request for exactly this order and amount (INR, valid payee, no repeated or unknown parameters), re-checked on every view and every `qr.png` request. With **Alpha UPI** that never happens, so the card shows **PAY SECURELY → PAY ₹X NOW**, which opens the hosted checkout in a new tab (its QR / UPI-app choice is the gateway's own), with the 05:00 timer and server-side confirmation unchanged. When a gateway does return a UPI request, the same page shows **SCAN AND PAY**, **DOWNLOAD QR** and **OPEN UPI APP**.
* Payments created before the fix are shown as hosted checkouts. The default payment instructions no longer promise a camera scan that opens a page.
* **Needs from you for a true "scan → UPI app opens" QR:** a gateway (or an Alpha UPI plan) that returns a UPI payment request. Ask Alpha UPI support whether one is available for your account, or add another adapter with `Gateways::register()`.
* Not tested against a live gateway or a real UPI payment (see [docs/QA-REPORT.md](docs/QA-REPORT.md) §5).

## Tags & Badges
Ten predefined tags plus custom ones, assigned per service; the automatic **TRIAL PACK** badge on every ₹5 service. (Migration `003`.)

## Alpha UPI: the current API
`X-API-Key` + `X-API-Secret`, `/api/create-order`, `/api/check-status`, a webhook without `event_id`; the older single-key API stays selectable.
