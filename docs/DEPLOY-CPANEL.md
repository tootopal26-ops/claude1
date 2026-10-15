# Deploying on cPanel / shared hosting

This guide takes you from an empty hosting account to a live, secure shop. Every step below was rehearsed against **real Apache + mod_php**
in all three folder layouts by `tools/qa/apache-check.mjs` (see [QA-REPORT.md](QA-REPORT.md)), so the file layouts and `.htaccess` rules here are tested, not guessed.

> **You do not need Composer, Node.js or SSH.** The compiled CSS/JS is committed to the repository. SSH / cPanel Terminal is only a convenience (CLI installer, account recovery).

---

## 1. What your hosting must provide

| Requirement | Notes |
|---|---|
| **PHP 8.1 or newer** (8.2 / 8.3 recommended) | cPanel → **MultiPHP Manager**. The installer refuses older versions with a clear message. |
| PHP extensions: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json`, `fileinfo`, `gd` | cPanel → **Select PHP Version → Extensions**. All are enabled by default on almost every host. `gd` with **WebP** is optional (logos are stored as PNG otherwise). `sodium` is optional (faster password hashing). |
| **MySQL 5.7+ or MariaDB 10.3+** | The user needs `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `SELECT/INSERT/UPDATE/DELETE`, `LOCK TABLES` (cPanel's "ALL PRIVILEGES" is right). |
| Apache or LiteSpeed with **`.htaccess` / `mod_rewrite`** | Standard on cPanel. (On NGINX-only hosts translate `public/.htaccess` — see *Other web servers*.) |
| **HTTPS** | cPanel → **SSL/TLS Status → Run AutoSSL**. Payments and the admin panel must run on https. |
| A way to run **cron every minute** | cPanel → **Cron Jobs**. If your host has none, use the HTTP cron URL (§6). |
| Outbound HTTPS (cURL) to your payment gateway and SMM provider | Some free hosts block it; paid shared hosting does not. |

Disk use is small (~5 MB of code; the database grows with orders and visitors).

---

## 2. Choose a folder layout

### Layout A — "everything in `public_html`" (simplest)
Upload the whole project into `public_html` (or a sub-folder). The root `.htaccess` sends web traffic into `/public` and **blocks** `app/`, `bin/`, `config/`, `database/`, `storage/`, `tests/`, `tools/`, `docs/`, `.env`, `.git`, `*.sql`, `*.log`…

```
public_html/
  .htaccess   ← routes everything into public/ and blocks the private folders
  app/  bin/  config/  database/  storage/  resources/  tools/  tests/  docs/  .env
  public/     ← index.php, .htaccess, assets/, uploads/
```

> **Heads-up:** layout A sends *every* request in that folder into `/public`, so it takes over the whole document root — including add-on-domain or sub-domain folders that live inside `public_html`. If other sites share the folder, use layout B, or install into a sub-folder of their own (below).

### Layout B — private code outside the web root (recommended)
Keep the code where the web server can never serve it, expose only `public/`:

```
/home/USER/smb/            ← app/, bin/, config/, database/, storage/, .env …  (NOT web-readable)
/home/USER/public_html/    ← the CONTENTS of public/ (index.php, .htaccess, assets/, uploads/)
```

Then edit **one line** in `public_html/index.php` — uncomment it and set your path:

```php
define('SMB_ROOT', '/home/USER/smb');
```

(Alternatively point the domain's *Document Root* at `/home/USER/smb/public` in cPanel → **Domains**, and no edit is needed.)

In both layouts `public/.htaccess` lets **only `index.php`** execute at the top of the web root (a stray test script or an uploaded shell can never run) and keeps `assets/` and `uploads/` inert. The rule is anchored to that folder on purpose: other PHP applications or static sites living in *sub-folders* of the same web root (an old blog in `public_html/blog`, say) keep working — verified against real Apache by `apache-check.mjs`.

### Installing in a sub-folder (`https://example.com/booster/`)
Layout A works unchanged inside a folder: upload the project to `public_html/booster/` and open `https://example.com/booster/install`. The application detects its base path automatically; after installing, `APP_URL` in `.env` contains the folder (`https://example.com/booster`). Verified in a real browser by `apache-check.mjs` (site C).

---

## 3. Prepare the database

1. cPanel → **MySQL® Databases** → create a database (e.g. `cpuser_smm`) and a user (e.g. `cpuser_smm`) with a long random password.
2. **Add the user to the database** with *ALL PRIVILEGES*.
3. Note the three values (cPanel prefixes them with your account name). The host is almost always `localhost`.

Prefer to import the schema yourself? **phpMyAdmin → Import → `database/schema.sql`**, then run the installer (it skips tables that already exist).

---

## 4. Upload and install

1. Zip the project (without `node_modules/` and `.git/`) → cPanel **File Manager → Upload** → **Extract** into the folder you chose in §2.
2. File permissions: folders `755`, files `644`. The web server must be able to **write** to `storage/logs`, `storage/cache`, `public/uploads` (755 is enough when PHP runs as your user, which is the cPanel default; use 775 if your host runs PHP as `nobody`) and to create `.env` in the project folder.
3. Open **`https://your-domain.com/install`**.
4. The installer first asks for a **one-time token** so strangers cannot install on your domain: open `storage/install-token.txt` in File Manager, copy the text, paste it.
5. Follow the wizard: **Server** (requirement checklist) → **Database** (test connection) → **Your site** (public URL, name, time zone) → **Admin** (username, email, strong password — a generator is built in) → **Install**.
6. The wizard writes `.env` (mode 0640, with freshly generated `APP_KEY` and `CRON_KEY`), creates the tables, seeds the default catalogue and settings, creates your admin account, and **deletes the token**. `/install` then answers 404 forever.

### Command-line alternative (SSH / cPanel Terminal)
```bash
cd /home/USER/smb
php bin/install.php --check          # prints the requirement checklist only
php bin/install.php --url=https://example.com --db-name=cpuser_smm --db-user=cpuser_smm --db-pass='…' \
    --admin-username=owner --admin-email=you@example.com        # asks for anything missing; the password is read without echo
```

### If the installer cannot write `.env`
It shows the exact text to paste into a new `.env` file (File Manager → **+ File** → `.env`) and carries on.

---

## 5. First sign-in: configure the shop

Sign in at **`/admin`**. The **system notices** at the top of every admin page list what is still missing (gateway, SMM provider, cron, temporary password…).

1. **Admin → Website:** site name, logo, favicon, accent colours, SEO title/description, support link (https or mailto only).
2. **Admin → Payment gateway (Alpha UPI):** the credentials are on your Alpha UPI dashboard (API Settings / API keys page).
   * **Gateway base URL:** `https://alphaupi.site` — the API host only (a pasted `https://alphaupi.site/api` is trimmed for you);
   * **API type:** *Current — API key + API secret* (the default). Your dashboard lists a **Production API Key** (sent as `X-API-Key`), a **Production API Secret** (`X-API-Secret`) and the **webhook** key. Choose *Older — API key only (Bearer)* only for the older SDK API;
   * enter the **API key**, **API secret** and **webhook secret** (all write-only: after saving, only a masked hint is shown). The gateway cannot go live without all three;
   * Alpha UPI works in **production mode only — there is no sandbox**. The Test / Production switch is just a label for you; your first test is a real **₹5 order** (the money goes to your own account);
   * copy the **Webhook URL** shown on the page — `https://your-domain.com/webhooks/payments/1` — into the **Webhooks portal** of the Alpha UPI dashboard (the signature is checked with the webhook secret; the header name does not matter);
   * press **Test connection** — a read-only lookup of an order that cannot exist: it proves the gateway is reachable and rejects a wrong key or secret. A correct key cannot be confirmed without a real order, so it ends with a yellow "reachable … the gateway did not refuse the credentials" — the **₹5 test order** in step 7 is the real proof. *"answered with a web page (HTTP 404)"* means the base URL is not the API host.
3. **Admin → SMM provider:** API URL (`…/api/v2`) and API key, then **TEST CONNECTION** (shows your provider balance). Everything is in ₹ — use a provider that bills in INR (there is no currency or exchange-rate setting; the charges it reports are read as rupees). Make it the default provider.
4. **Admin → Services:** every service starts as **MANUAL**. To automate one: set mode **AUTO**, enter its **SMM service ID** and **provider cost** (what the provider charges you for one purchase of that pack), save. The editor shows the profit per order. *An AUTO service without a service ID or active provider is hidden from the store automatically.*
5. **Admin → Advertising:** set your cost per click (default ₹1.08) and the qualification time (default 3 s).
6. **Admin → Security:** change the password if you used the temporary one; review active sessions.
7. Place a **₹5 test order** yourself and watch it travel: pay → *Payment received* → provider order → completed. Check the dashboard numbers afterwards.

---

## 6. Cron (background tasks) — required

The cron job expires unpaid payment sessions (and double-checks them with the gateway first), retries provider orders after an outage, syncs provider order status and real cost, refreshes reports, and purges old rate-limit/session rows. **Run it every minute.**

**cPanel → Cron Jobs → "Once Per Minute"** (the exact command is shown, copy-ready, in **Admin → System**):

```
* * * * * /usr/local/bin/php /home/USER/smb/bin/cron.php >/dev/null 2>&1
```

*No cron on your plan?* Use the HTTP trigger with an external pinger (cron-job.org, UptimeRobot — every minute):

```
https://your-domain.com/cron/run?key=<CRON_KEY from .env>
```

As a last resort the site also runs the tasks from visitor traffic (at most every 3 minutes). That keeps small shops working, but a real cron job is more reliable: with no visitors nothing would expire or retry.

---

## 7. HTTPS, headers and cookies

* Set `APP_URL=https://your-domain.com` in `.env` (the installer does this from the URL you enter). With an `https://` URL the application automatically sends **HSTS**, marks every cookie **Secure**, and uses the `__Host-` prefixed admin session cookie.
* Force https for visitors who type `http://`: add this at the top of `public/.htaccess` (or use your host's "Force HTTPS" switch):

  ```apache
  RewriteEngine On
  RewriteCond %{HTTPS} !=on
  RewriteCond %{HTTP:X-Forwarded-Proto} !=https
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  ```
* Behind Cloudflare or another proxy, set `TRUSTED_PROXY_HEADER=CF-Connecting-IP` so rate limits see real visitor IPs — **only** if all traffic goes through that proxy.
* The Content-Security-Policy forbids inline scripts/styles; do not add third-party snippets (analytics tags, chat widgets) without extending the policy in `app/Core/SecurityHeaders.php`.

---

## 8. Security checklist before you announce the site

- [ ] `https://your-domain.com/.env`, `/app/bootstrap.php`, `/storage/installed.lock`, `/database/schema.sql` all answer **403 or 404** (layout B: they simply do not exist under the web root).
- [ ] `/install` answers 404; `storage/install-token.txt` no longer exists.
- [ ] `.env` is not world-writable (`chmod 640 .env`); `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] Gateway is in **Production** mode and the webhook URL is configured in the gateway dashboard.
- [ ] Admin password is long and unique; consider a second admin account (`php bin/admin.php create …`) so you are never locked out.
- [ ] Cron job is running (Admin → System shows "Last run … ago").
- [ ] Optional hardening: delete `tools/`, `tests/`, `docs/`, `node_modules/` from the server (they are never needed in production, and the root `.htaccess` already blocks them).
- [ ] Take a backup (§10) and store it somewhere other than the server.

---

## 9. Upgrading

1. Back up (§10).
2. Upload the new files over the old ones, **keeping** `.env`, `storage/` and `public/uploads/`.
3. If the release contains new files in `database/migrations/`, apply them: `php bin/migrate.php` (or **Admin → System → Apply database updates** — the admin header shows a banner while updates are pending). The store keeps selling in the meantime; the release that adds **Tags & Badges** (`003_service_tags.sql`) simply shows no badges, and the Tags page asks for the update, until it has been applied. The migration keeps what you had: an old free-text badge such as `PREMIUM` becomes an assignment of the matching tag, any other text becomes a custom tag, and nothing is deleted.
4. Hard-refresh your browser (asset URLs carry a `?v=` version, so visitors get the new CSS/JS automatically).

---

## 10. Backups

| What | How | Why |
|---|---|---|
| Database | cPanel → **Backup → Download a MySQL Database Backup** (or phpMyAdmin → Export) | all orders, payments, settings, encrypted keys |
| `.env` | download it | contains `APP_KEY`: **without it the encrypted gateway/SMM keys cannot be decrypted** (you would re-enter them) |
| `public/uploads/` | download the folder | your uploaded logos/icons |

Restore: import the SQL, put `.env` and `public/uploads/` back.

---

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| "This application needs PHP 8.1 or newer" | cPanel → MultiPHP Manager → choose 8.1+ for the domain. |
| Blank page / HTTP 500 right after upload | Check `storage/logs/app-*.log`. Usual causes: wrong PHP version, a missing extension (Admin → System lists them), `.htaccess` line your host rejects (delete the line it names). Temporarily set `APP_DEBUG=true` to see the error, then set it back. |
| Installer: "Database connection failed" | Re-check the prefixed DB name/user, that the user is **added to the database**, and the host (`localhost`). |
| Installer step "Server" shows a red ✗ | Fix what it names (extension or folder permission) and press **Re-check**. |
| `/admin` redirects to the sign-in page forever | Your browser blocks cookies for the site, or `APP_URL` does not match the address you use (http vs https, www vs non-www). Fix `APP_URL` in `.env`. |
| "Connection problem — retrying…" on the payment page | The visitor's connection dropped — the page recovers by itself. If it happens for everyone, check that the server can reach the gateway (Admin → Payment gateway → Test connection). |
| Customers pay but the order stays "Waiting for payment" | The gateway's webhook is not reaching the site. Check the webhook URL in the gateway dashboard and **Admin → Payments → Webhook events** (invalid signatures mean the webhook secret differs). The page also verifies with the gateway whenever the customer returns, and cron double-checks every pending session. |
| AUTO orders sit in **PAID** | Provider unreachable → they retry automatically (1, 5, 20, 60 min…, then FAILED for a human). Check Admin → SMM provider → TEST CONNECTION. |
| AUTO order in **FAILED** | Read the reason in the order drawer ("Provider rejected…", "Not enough funds…"), fix the cause, press **Retry**, or **Move to manual**. |
| "Cron has never run" banner | Add the cron job (§6). |
| Locked out of the admin panel | `php bin/admin.php unlock --username=owner` and/or `php bin/admin.php reset-password --username=owner` (prints a new password once). No SSH? Use phpMyAdmin: `UPDATE admins SET failed_logins=0, locked_until=NULL;` |
| Uploaded logo is rejected | Use PNG, JPG or WebP (SVG is refused on purpose — it can carry scripts), under 2 MB and at most 16 megapixels (about 4000×4000). Images are re-encoded on upload, so metadata (EXIF/GPS) is stripped too. |
| Changed domain / moved to https | Edit `APP_URL` in `.env`; update the webhook URL in the gateway dashboard. |

### Other web servers
Apache and LiteSpeed (what cPanel runs) are the tested targets. `public/.htaccess` does three things: (1) send every non-file request to `index.php`, (2) deny dot-files and PHP execution anywhere except `index.php`, (3) long-lived caching/compression for static files. On NGINX/LiteSpeed-without-htaccess translate those, and make sure `uploads/` can never execute PHP and the folders outside `public/` are not served. Example NGINX core:

```nginx
root /home/USER/smb/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ { if ($uri !~ "^/index\.php$") { return 404; } include fastcgi_params; fastcgi_pass unix:/run/php/php8.3-fpm.sock; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
location ~ /\.(?!well-known) { deny all; }
location ^~ /uploads/ { location ~ \.php$ { return 404; } }
```
