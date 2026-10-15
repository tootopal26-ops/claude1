# Run the whole site on your own computer (demo mode)

One command gives you the complete application — storefront, admin panel, a **fake payment gateway** and a **fake SMM panel** — filled with about 75 days of demo orders, so you can click through everything without a hosting account, Apache or a real gateway.

```bash
php tools/local-demo.php
```

It installs the site, creates the demo data, starts everything on `127.0.0.1`, prints your admin login and opens the browser. Leave the window open while you use the site; press **Ctrl+C** to stop it all.

> **Local use only.** It listens on `127.0.0.1`, uses throw-away credentials and fake payment/SMM servers. To run a real shop use [DEPLOY-CPANEL.md](DEPLOY-CPANEL.md).

## What you need

| | Why | Windows | macOS | Linux / WSL |
|---|---|---|---|---|
| **PHP 8.1+** with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `gd`, `fileinfo` | runs the site | [XAMPP](https://www.apachefriends.org) (PHP is `C:\xampp\php\php.exe`) | `brew install php` | `sudo apt install php-cli php-mysql php-curl php-mbstring php-gd php-xml` |
| **MySQL or MariaDB**, running | the database | XAMPP → press *Start* next to MySQL | `brew install mariadb && brew services start mariadb` | `sudo apt install mariadb-server` |
| **Node.js 18+** *(optional but recommended)* | runs the fake payment gateway and SMM panel — without it you can browse, but a checkout cannot complete | [nodejs.org](https://nodejs.org) | `brew install node` | `sudo apt install nodejs` |

Then open a terminal **in the project folder** and run the command (on Windows with XAMPP: `C:\xampp\php\php.exe tools\local-demo.php`).

## Options

```text
php tools/local-demo.php [--db-host=127.0.0.1] [--db-port=3306] [--db-user=root] [--db-pass=] [--db-name=smb_demo]
                         [--port=8080] [--fresh] [--no-open]
```

| Option | Meaning |
|---|---|
| `--db-user`, `--db-pass`, `--db-host`, `--db-port` | the MySQL account that may create the demo database. Default: `root` with **no password** on `127.0.0.1:3306` (XAMPP, Laragon, Homebrew). MAMP: `--db-port=8889 --db-pass=root`. |
| `--db-name` | database to create / reuse (default `smb_demo`) |
| `--port` | port of the website (default `8080`; the fake gateway uses `4010`, the fake SMM panel `4020`) |
| `--fresh` | throw the demo database away and rebuild it (new demo data, new admin password) |
| `--no-open` | don't open the browser |

Running it again **keeps** your data and admin password (the login is also saved in `storage/local-demo-login.txt`).

## What to try

1. **Order something.** Open the site → pick *Instagram* or *YouTube* → **BUY NOW** → type `@someone` or a link (watch the live ✓ *Valid input* / ⚠ *Invalid username/link*) → **PROCEED TO PAYMENT**.
2. **Pay.** On the *SCAN AND PAY* page press **PAY ON THIS DEVICE** → on the fake gateway page press **Simulate payment success** → you land back on the site on *Payment successful*, then **TRACK ORDER**. (Try *Simulate failure* too, or just wait out the 05:00 timer to see *Payment session expired*.)
3. **Watch it being delivered.** Services marked **AUTO** are sent to the fake SMM panel, which moves the order *Pending → In progress → Completed* by itself within a few minutes (the launcher also runs the site's background job every minute, exactly like the cron job on a real host). **MANUAL** services wait in *Admin → Orders* for you — try **APPROVE ALL**.
4. **Run the shop.** Sign in at `/admin` with the printed login: the dashboard (14 KPI cards, profit breakdown, charts for Today / 7 / 30 / 90 days / Lifetime / custom range), Orders (filters, details drawer), Services (edit prices, AUTO ⇄ MANUAL, pick a badge for a card) with its **Tags & Badges** tab (create, recolour, enable / disable your own badges), Website (name, logo upload, colours), Payment gateway and SMM API (**TEST CONNECTION**), Advertising (cost per click), Security, Audit log, System.
5. **Phone view.** In your browser press F12 → toggle the device toolbar and pick a phone.

The numbers in the dashboard come from random demo data, so the revenue and profit figures mean nothing — including a negative net profit on days when the demo ad spend exceeds the demo sales.

## Troubleshooting

| You see | Do this |
|---|---|
| *Could not connect to MySQL/MariaDB* | start the database server (XAMPP *Start*, `brew services start mariadb`), or give the right account: `--db-user=… --db-pass=…`. On Ubuntu/Debian `root` only works through `sudo`, so create a normal user once (the message prints the exact command). |
| *PHP is missing the extension(s): …* | enable them in `php.ini` (XAMPP: `C:\xampp\php\php.ini`, remove the `;` in front of the `extension=` line) and run again. |
| *Port 8080 is already in use* | `--port=8081` |
| *A .env file already exists … not created by this launcher* | the folder already holds a real installation; the launcher refuses to touch it. Use a fresh copy of the project. |
| Checkout says *payments are temporarily unavailable* | Node.js is missing or the fake gateway could not start — see `storage/logs/demo-gateway.log`. Install Node.js 18+ and run again. |
| *The database … already contains an installation* | `--fresh` |

Logs: `storage/logs/demo-web.log`, `demo-gateway.log`, `demo-smm.log`.

## Remove it

Stop the launcher (Ctrl+C), then drop the `smb_demo` database and delete `.env`, `storage/installed.lock` and `storage/local-demo-login.txt` from the project folder.

## What was tested

The launcher was run from a clean copy of the project on **Linux** (PHP 8.3, MariaDB 10.11, Node 22): a first run from scratch, a re-run that reuses the data, the default `root` account, a run without Node.js, refusal to touch a foreign `.env`, a clean Ctrl+C, and a browser driving a complete order → fake-gateway payment → tracking → admin sign-in. It has **not** been run on Windows or macOS (it uses only cross-platform PHP functions, but treat those two as untested).
