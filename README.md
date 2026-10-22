# Mini Max

A Free Fire esports tournament platform: an Android player app, a
combined Admin/Staff panel, and the REST API tying them together.
Players sign up, browse and join tournaments (free or paid-entry),
compete, and get paid out through wallets backed by UPI deposits and
withdrawals. Staff and admins run tournaments, publish results, review
risk alerts, and manage payouts through the same panel, gated by a
permission system shared with the API.

## Repository layout

```
backend/   Laravel app — Admin panel, Staff panel, and the REST API,
           all one app on one guard-separated codebase (see "Why one
           Laravel app" below). backend/public is the web doc root.
mobile/    The Expo/React Native player app (com.minimax.esports).
docs/      Setup and operations guides (see below).
```

### Why one Laravel app, not three

Admin, Staff, and the API all have to apply *identical* financial
rules — wallet deduction priority, join-window timing, publish-once
result semantics. Splitting them into separate apps would either
duplicate that logic (unacceptable where money is involved) or add an
internal HTTP hop per action for no real isolation benefit at this
scale. Every money-moving operation instead lives in exactly one
`app/Domain/*` action class, called by both the API controllers and the
Livewire admin/staff components.

Admin and Staff share one `staff_users` table and one `staff` guard — an
"Admin" is simply a staff account holding the full permission preset (or
`is_super_admin`), not a separate authentication system. Players use a
separate `api` guard (Sanctum tokens, one per device).

## Getting started (local development)

**Backend:**
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```
This seeds a super-admin account (`admin` / the value of
`SEED_ADMIN_PASSWORD` in `.env`, falling back to `change-me-now`
locally — never leave that fallback in place anywhere reachable by
anyone else) and one demo game/tournament.

**Mobile:**
```bash
cd mobile
npm install
npx expo start
```
Point it at a locally running backend via `extra.apiBaseUrl` in
`mobile/app.json` (defaults to `http://localhost:8000/api/v1`).

**Running tests:**
```bash
cd backend && php artisan test
cd mobile && npx tsc --noEmit
```

## Documentation

- [`docs/deployment.md`](docs/deployment.md) — deploying the backend to
  a CyberPanel VPS: provisioning, cron, Supervisor, SSL, and the
  production `.env` checklist.
- [`docs/backup-restore.md`](docs/backup-restore.md) — what
  `backup:run` backs up, how to restore it, and why a local backup
  alone isn't a backup plan.
- [`docs/mobile-release.md`](docs/mobile-release.md) — building and
  releasing the Android app via EAS, the self-managed signing-key
  strategy, and the current open question around iOS distribution.
- [`docs/firebase-setup.md`](docs/firebase-setup.md) — wiring up
  Firebase Cloud Messaging for push notifications.
- [`docs/smoke-test-checklist.md`](docs/smoke-test-checklist.md) — the
  manual end-to-end check (signup → join → result → payout →
  withdrawal) to run against a staging deploy before it goes live.
