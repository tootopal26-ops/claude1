# Deploying Mini Max to a CyberPanel VPS

This covers the `backend/` Laravel app — the Admin panel, Staff panel, and
the REST API the mobile app talks to. All three are served by one Laravel
app on one domain; see the root README for why.

For the mobile app's build/release process, see
[`mobile-release.md`](mobile-release.md). For backup/restore, see
[`backup-restore.md`](backup-restore.md). For Firebase push notification
setup, see [`firebase-setup.md`](firebase-setup.md).

## 1. Prerequisites

A CyberPanel VPS with a website already created for the target domain, and:

- **PHP 8.3**, with the extensions Laravel 13 needs: `bcmath`, `ctype`,
  `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`,
  `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`. `bcmath` is
  not optional here — every money calculation in `WalletService` goes
  through `bcadd`/`bccomp`/etc., so a build without it will fail loudly
  the first time a wallet is touched, not silently produce wrong numbers.
  CyberPanel's PHP selector can install/enable these per-website.
- **MySQL 8 or MariaDB 10.6+**, with a database and a dedicated user
  (not `root`) created for the app, from CyberPanel's Database Manager.
- **Composer 2**, **Node.js 20+** and npm, and **git** on the server
  (CyberPanel's SSH access, or deploy via a CI runner that then rsyncs
  the built artifact — either way, the build steps below are the same).

## 2. First-time deploy

```bash
# As the website's Linux user, inside its home directory:
git clone <your-repo-url> minimax
cd minimax/backend

composer install --no-dev --optimize-autoloader
npm ci
npm run build          # builds resources/css + resources/js into public/build

cp .env.production.example .env
# Edit .env: DB credentials, APP_URL, APP_KEY (next step fills this in),
# SEED_ADMIN_PASSWORD, ALPHA_UPI_* (real merchant credentials), and
# FIREBASE_CREDENTIALS (see firebase-setup.md) — see the production
# checklist below for the full list.

php artisan key:generate
php artisan migrate --force
php artisan db:seed --force   # creates the permission catalog + the
                               # first super-admin account (username
                               # "admin", password from
                               # SEED_ADMIN_PASSWORD) — review
                               # database/seeders/DatabaseSeeder.php
                               # first if you don't want its example
                               # game/tournament row.
php artisan storage:link      # publishes storage/app/public (game card and
                               # leaderboard banner images) at /storage —
                               # without it those images 404 in the app.

chmod -R ug+w storage bootstrap/cache
```

### Document root

In CyberPanel's website settings, point the domain's document root at
`minimax/backend/public`, **not** `minimax/backend`. Everything outside
`public/` (the app code, `.env`, `storage/`) must not be web-reachable —
this is the default Laravel layout, not something specific to this app,
but it's the one setting that, if missed, exposes `.env` to the internet.

### SSL

Use CyberPanel's built-in Let's Encrypt issuance (Websites → the site →
Issue SSL) once the domain's DNS points at the server. Force HTTPS at the
webserver level (CyberPanel's "Force HTTPS" toggle) rather than in
application code.

## 3. Cron — the scheduler

Every periodic job in this app (closing tournaments' join windows,
auto-cancelling underfilled tournaments, generating recurring
tournaments, maintaining leaderboards, staff salary settlements, storage
cleanup, wallet reconciliation, nightly backups, and `ads:maintain` every
five minutes — expiring unanswered rewarded-ad claims and lapsed redeem
reservations, opening each finished Ads Earning week for review, pruning
old ad telemetry) runs through Laravel's
scheduler, defined
in `backend/bootstrap/app.php`'s `withSchedule()` — not as separate cron
entries. The server needs exactly one cron line, from CyberPanel's Cron
Jobs UI, running as the website's user:

```
* * * * * cd /home/<user>/minimax/backend && php artisan schedule:run >> /dev/null 2>&1
```

Run `php artisan schedule:list` on the server after deploying to confirm
every job registered (see `backend/bootstrap/app.php` for the current
list — new scheduled commands are added there, not as new cron lines).

The queue worker below is also what credits an Ads Earning settlement
(`CreditAdSettlementJob`) after an Admin presses *Credit settlement*; without
a running worker the settlement stays in "crediting". Ads setup (LevelPlay
keys, the reward callback URL, test mode) is in `docs/ads-monetization.md`.

## 4. Supervisor — the queue worker

Queued jobs (push notification delivery, currently the only one) run on
the `database` queue connection and need a long-running worker process,
which cron can't provide — that's what Supervisor is for. CyberPanel
ships Supervisor; add a config like this (adjust the path and user):

```ini
; /etc/supervisor/conf.d/minimax-queue.conf
[program:minimax-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /home/<user>/minimax/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/home/<user>/minimax/backend
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=<user>
numprocs=2
redirect_stderr=true
stdout_logfile=/home/<user>/minimax/backend/storage/logs/queue-worker.log
stopwaitsecs=3600
```

`--max-time=3600` makes each worker process recycle itself hourly, which
Supervisor then restarts automatically — this picks up code deploys
without a manual `queue:restart`, though running `php artisan
queue:restart` as the last step of every deploy (below) is still the
reliable way to make sure a deploy's changes land immediately rather than
up to an hour late.

```bash
supervisorctl reread
supervisorctl update
supervisorctl start minimax-queue:*
```

## 5. Subsequent deploys

```bash
cd minimax/backend
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

`config:cache`/`route:cache`/`view:cache` are safe and recommended in
production (they are not safe to run in development, where `.env` and
routes change constantly — don't add them to a local setup). If
something goes wrong after caching, `php artisan optimize:clear` reverts
all three.

## 6. Production `.env` checklist

Starting from `backend/.env.production.example` (not the plain
`.env.example`, which is Laravel's local-dev default — sqlite,
`APP_DEBUG=true`), confirm before going live:

- `APP_ENV=production`, `APP_DEBUG=false` — a stack trace leaking to a
  player or a staff member outside your team is a real information
  disclosure here, not just an inconvenience.
- `APP_KEY` set (via `php artisan key:generate`, once, and never
  regenerated afterward — regenerating it invalidates every existing
  session and Sanctum token).
- `APP_URL` matches the real domain (used when building absolute URLs,
  e.g. in queued notification payloads).
- `DB_*` point at the real database, with a non-root MySQL user scoped to
  only that database.
- `SEED_ADMIN_PASSWORD` set to a real generated password before the
  first `db:seed` — the fallback (`change-me-now`) must never reach a
  production database.
- `ALPHA_UPI_*` set to the real, rotated merchant credentials (see the
  payments-addendum note in the project's own history: example keys that
  were pasted into the original spec were never hardcoded anywhere and
  must not be reused — generate fresh ones with the provider).
- `FIREBASE_CREDENTIALS` set, or the default
  `storage/app/firebase/service-account.json` path populated — see
  `firebase-setup.md`.
- `SESSION_SECURE_COOKIE=true` (only safe once HTTPS is actually
  enforced — see the SSL step above).
- `LOG_STACK=daily` rather than `single`, so logs rotate instead of
  growing forever (`.env.production.example` already sets this).

## 7. Staging smoke test

Before pointing real users (or real money) at a deploy, run the
checklist in [`smoke-test-checklist.md`](smoke-test-checklist.md):
signup → join a free tournament → staff publishes a result → the
winner's wallet is credited → the winner requests and is paid a
withdrawal. Each step is independently covered by the automated test
suite (`php artisan test`); the smoke test's value is proving the
*deployed* stack — real HTTP, real queue worker, real scheduler, real
MySQL — behaves the same way end to end, which an in-process PHPUnit run
against sqlite cannot by itself guarantee.
