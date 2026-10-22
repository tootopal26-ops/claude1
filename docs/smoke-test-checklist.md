# Staging smoke test checklist

Run this against a real staging deploy — real HTTP, a real queue
worker, the real scheduler, a real MySQL database — before pointing
players (or real money) at it. It's the one check the automated test
suite (`php artisan test`, sqlite in-memory, in-process) can't give you
by itself: proof the *deployed* pieces actually talk to each other.

Every step below was run for real against a locally-served instance
(`php artisan serve` + `php artisan queue:work` + MySQL) as part of
building this checklist; this is the exact sequence, not a sketch of
one.

## Prerequisites

- A running, migrated, seeded deploy (`php artisan migrate --seed`).
- A queue worker running (`php artisan queue:work`, or the Supervisor
  job from `deployment.md` already up).
- The super-admin account's credentials (`admin` / `SEED_ADMIN_PASSWORD`).
- On a fresh deploy with no legal documents published yet, registration
  still succeeds (`accept_terms` only requires the box be checked — it
  doesn't require a currently-published document to exist). Publish a
  real Terms & Conditions / Privacy Policy from the Admin → Legal
  section before real players sign up; this checklist doesn't depend on
  one existing, but a real launch should not skip it.

## 1. Signup

Register a test player — through the mobile app, or directly:
```bash
curl -X POST https://<your-domain>/api/v1/auth/register \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"username":"smoketester","mobile":"9876543210","email":"smoketester@example.com","date_of_birth":"2000-01-01","state":"Delhi","password":"password123","password_confirmation":"password123","accept_terms":true}'
```
**Pass:** `201`-equivalent response with a `data.token` and `data.user`.

## 2. Create (or use) a joinable tournament, and join it

From the Admin panel (Tournaments → Create), create a free-entry Solo
tournament with `scheduled_start_at` a minute or two out. As the test
player:
```bash
curl https://<your-domain>/api/v1/tournaments -H "Authorization: Bearer <token>" -H "Accept: application/json"
curl -X POST https://<your-domain>/api/v1/tournaments/<id>/join \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"players":[{"in_game_name":"SmokeTesterIGN","uid":"123456789"}]}'
```
**Pass:** the join call returns the created entry; the Admin tournament
view shows the new entry.

**Check the scheduler is actually running:** once the join window
passes (`scheduled_start_at` + the configured join-window minutes),
confirm the tournament's status flips from `upcoming` to `ongoing` on
its own within a minute or two — that's `tournaments:close-joining-window`
firing from cron, not something to trigger by hand on a real staging
box. (This checklist's own dry run instead backdated the test
tournament and invoked that one command directly, to avoid an
open-ended wait — do that only if you're validating the command
in isolation, not as a substitute for confirming cron itself is wired
up per `deployment.md`.)

## 3. Staff publishes a result

From the Admin panel (the tournament's Results screen, once its status
is `ongoing`), submit rank/kills/prize for the test entry. Fund the
tournament's prize pool first if it's a free-entry tournament without
one already (prize payouts are validated against `prize_pool`, which a
free *entry fee* doesn't imply).

**Pass:** the Admin view shows the published result; `wallet:reconcile`
run immediately after reports no drift.

## 4. Wallet is credited

```bash
curl https://<your-domain>/api/v1/wallet -H "Authorization: Bearer <token>" -H "Accept: application/json"
```
**Pass:** `eligible_winnings_balance` reflects the prize just awarded.

## 5. Withdrawal is requested and paid

As the player:
```bash
curl -X POST https://<your-domain>/api/v1/withdrawals \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"amount":"<prize amount>","upi_id":"smoketester@upi"}'
```
As staff, from the Admin Withdrawals screen: Approve → Mark Processing
→ Mark Paid (with a settlement reference).

**Pass:**
```bash
curl https://<your-domain>/api/v1/withdrawals -H "Authorization: Bearer <token>" -H "Accept: application/json"
```
shows `status: "paid"` with the settlement reference recorded, and the
wallet (`GET /api/v1/wallet`) has dropped back to the pre-prize balance.

## 6. Notifications actually delivered

Check `php artisan tinker --execute='echo DB::table("failed_jobs")->count();'`
is `0` and `DB::table("jobs")->count()` is `0` (nothing stuck) —
confirms the live queue worker, not just job dispatch, is working.
If Firebase is configured (`docs/firebase-setup.md`), also confirm the
test device actually received the three push notifications this flow
triggers (join confirmation, result published, withdrawal paid).

## 7. Clean up

Delete the smoke-test tournament/entries/withdrawal (or just re-run
`migrate:fresh --seed` if this was a disposable staging database, not
one carrying real pre-launch data).
