# Mini Max — Final QA: Test Results

This log is filled in as the audit proceeds (see `BUG_REPORT.md` for
defects, `SCREENSHOT_INDEX.md` for captured evidence). Status key:
✅ pass · ⚠️ pass with issue filed · ❌ fail · 🚫 blocked/untested.

## 0. Environment

| Check | Result |
|---|---|
| Android real-device/emulator testing | 🚫 Blocked — see ENV-001 in BUG_REPORT.md |
| Admin/Staff web panel (Playwright + Chromium) | ✅ Done — see §1/§2 |
| Backend API (real HTTP, real MySQL) | ✅ Done — exercised throughout §1–§4 and the mobile section below |
| Mobile app (no device available) | ⚠️ Substitute coverage only — see §5 |

The stack used for every live check in this report: `service mariadb
start`, `php artisan serve --port=8001` against a real (not SQLite/
in-memory) MySQL database, seeded via `migrate:fresh --seed` then
populated with real accounts/tournaments/transactions created through
the app's own forms and APIs (never inserted directly into the
database, except the one Sanctum token mint needed to call the player
API without a browser). All screenshots are full-page Playwright
captures of this real server; none are mocked or hand-drawn.

## 1. Admin panel — full walkthrough

Every route reachable from the super-admin (`admin`) account's nav was
opened at least once in both an empty-data state and, after seeding
realistic data (2 players, 3 tournaments in different end states, a
deposit, a full withdrawal lifecycle ×3, a staff account, a permission
preset), a populated state. See `SCREENSHOT_INDEX.md`'s Admin panel
table for the full list (43 screenshots, `01`–`43`).

| Area | Result |
|---|---|
| Tournaments (list, create, entries, credential release, results) | ✅ |
| Players (list, detail, wallet adjustment, ban/unban) | ✅ |
| Withdrawals (list, approve, reject, processing, paid, failed) | ✅ — see §4 for the full state-machine proof |
| Deposits (list, populated with success/failed/pending rows) | ✅ |
| Notifications (compose, audience, send, history) | ✅ |
| Staff accounts + permission presets (CRUD, validation errors) | ✅ |
| Templates (recurring tournaments) | ✅ |
| Leaderboards | ✅ |
| Staff salary (rules, settlements) | ✅ |
| Storage dashboard | ✅ |
| Legal documents + platform settings | ✅ |
| Audit log (populated with real player/staff/system actions) | ✅ |
| Risk alerts | ✅ (empty state only — no risk-alert-triggering scenario was deliberately forced beyond what the real actions above happened to produce; none fired during this session's real usage) |

Console and network monitoring (Playwright `page.on('console')` /
`page.on('response')`) ran on every page visited in this section; no
unhandled console errors and no failed (status ≥ 400) requests were
observed on any successful navigation. The only ≥ 400 responses seen
anywhere in this audit were the ones deliberately produced to test
validation and permission boundaries (expected 422s/403s) and the
BUG-002 reproduction (expected-at-the-time 500, now a clean 422).

Bugs found in this area: **BUG-001** (nav overlap, every page),
**BUG-003** (post-login landing page), **BUG-004** (table column
padding, 2 of these screens) — all fixed and verified, see
`BUG_REPORT.md`.

## 2. Staff panel — role-restricted walkthrough

Tested with 3 accounts: the super-admin (bypasses all permission
checks), a zero-permission staff account, and a staff account holding
only `legal.manage` + `notifications.send`. See
`SCREENSHOT_INDEX.md`'s Staff panel table (`01`–`05`).

| Check | Result |
|---|---|
| Nav hides sections the account can't access | ✅ |
| Direct navigation to a restricted route still 403s (not just hidden nav) | ✅ — confirmed on `/admin/withdrawals` with the limited account |
| Permitted routes load correctly for a limited account | ✅ — confirmed `/admin/legal` and `/admin/notifications` |
| Post-login landing page works for an account with 0 permissions | ✅ (after BUG-003 fix — was a bare 403 dead-end before) |
| Post-login landing page works for an account with a non-default permission subset | ✅ |

Bug found in this area: **BUG-003** — fixed and verified.

## 3. Backend automated test suite

| Check | Result |
|---|---|
| `php artisan test --compact` | ✅ 365/365 passing, 863 assertions (final run, after all fixes) |
| `vendor/bin/pint --test --format agent` | ✅ clean (final run, after all fixes) |

This suite was re-run after every single fix in this audit (BUG-001
through BUG-005), not just once at the end — see each bug's
"Verification" entry in `BUG_REPORT.md` for the point-in-time count.
It already covered concurrency (last-slot booking races, concurrent
webhook delivery, withdrawal-vs-debit races) and the full business-
rule suite from Phases 1–16; this audit did not need to add coverage
for those, only for the gaps BUG-002's and BUG-005's fixes introduced.

## 4. End-to-end business-rule verification

All of the following were driven for real — live HTTP calls and/or a
real Playwright browser session against the real server — not unit
tests in isolation. See `SCREENSHOT_INDEX.md`'s "End-to-end
business-rule verification" table for the withdrawal/result-revision
screenshots.

| Flow | Result | Evidence |
|---|---|---|
| Signup → login → browse → join a free tournament | ✅ | Exercised live via the API in this session and already covered end-to-end by Phase 1's original verification pass |
| Join-window closure (`tournaments:close-joining-window`) | ✅ | Backdated a tournament's `scheduled_start_at` via tinker, ran the real artisan command, confirmed the window closed and late joins are rejected |
| Minimum-players auto-cancel + refund (`tournaments:auto-cancel-underfilled`) | ✅ | Same technique — confirmed the underfilled "QA Underfilled Cup" tournament auto-cancelled and its one entry's fee was refunded exactly (ledger entry `refund +10.00`) |
| Result publication → wallet credited exactly once | ✅ | "QA Paid Cup" — rank 1, 7 kills, 50.00 prize credited via a `result_correction` ledger entry |
| Result revision/correction | ✅ | Resubmitted the same tournament's results (9 kills, 80.00 prize) through the Admin UI; `revision_number` incremented 1→2, old result's `superseded_by_id` set, and the wallet received exactly the +30.00 delta (not the full 80.00 again) — confirmed via direct ledger inspection |
| Withdrawal: request → approve → processing → paid | ✅ | Full lifecycle driven live, settlement reference required and recorded (screenshots `28`–`31` in the Admin panel index) |
| Withdrawal: request → reject | ✅ | Amount released back to eligible winnings balance exactly (ledger: `-75.00` reserve, `+75.00` release) |
| Withdrawal: request → approve → processing → markFailed | ✅ | Amount released back exactly (ledger: `-60.00` reserve, `+60.00` release) |
| Wallet consistency across all of the above | ✅ | Final winnings balance (230.00) independently reconciled against every ledger entry's running total by hand — matches exactly |
| Admin/Staff permission boundaries | ✅ | See §2 |
| Duplicate-webhook dedup | ✅ (pre-existing automated coverage) | Covered extensively by Phase 16's `tests/Feature/Payments/*` concurrency tests; not re-exercised live in this pass since it was already proven and nothing in this audit touched that code path |

No real payments, real withdrawals (UPI payouts never actually left
the sandbox — "paid"/"failed" are internal state transitions, no real
bank transfer occurs without real gateway credentials), mass
notifications, or destructive production actions were performed
anywhere in this audit.

## 5. Mobile app — substitute coverage (no real device available, see ENV-001)

ENV-001 establishes that real Android emulation is infeasible in this
sandbox (no SDK/adb/KVM, and `react-native-web` was never installed in
this project, so there is no way to render the Expo app visually
here). The coverage below is the substitute used instead: real HTTP
traffic against the live backend, plus a full static read-through of
the mobile source. It does **not** replace real-device testing — see
"What this does NOT cover" at the end.

### (a) API flows exercised live over real HTTP (not mocked)

All of the following were run against the actual running Laravel
server (`php artisan serve`) and a real MySQL database during this QA
session — the same network boundary the mobile app's `apiRequest()`
client calls into:

| Flow | What it proved | Automated test coverage |
|---|---|---|
| Registration validation errors | Bad/missing fields on `POST /api/v1/auth/register` return clean `422`s the mobile `ApiError` path can render | `tests/Feature/RegistrationTest.php`, `tests/Feature/RegistrationEligibilityTest.php`, `tests/Feature/Legal/RegistrationLegalAndJurisdictionTest.php` |
| Login (valid + rate-limited) | `POST /api/v1/auth/login` issues a Sanctum token on success; repeated bad attempts are throttled | `tests/Feature/LoginRateLimitTest.php` |
| Tournament join — success | `POST /api/v1/tournaments/{id}/join` atomically books a slot and is reflected immediately in the Admin entries view | `tests/Feature/TournamentJoinTest.php` |
| Tournament join — insufficient funds | A paid-entry join with an underfunded wallet returns a clean validation error, not a 500 | `tests/Feature/TournamentJoinTest.php`, `tests/Feature/Wallet/WalletServiceTest.php` |
| Wallet balance + ledger | `GET /api/v1/wallet` and `GET /api/v1/wallet/ledger` return the exact deposit/winnings split the mobile `wallet.tsx` screen reads | `tests/Feature/Wallet/WalletApiTest.php` |
| Deposit initiation hitting an unreachable gateway | Reproduced the real connection failure to `alphaupi.site` (outside this sandbox's network policy) that became **BUG-002**; confirmed the fix now returns a clean `422` instead of a raw 500/stack trace | `tests/Feature/Payments/DepositApiTest.php`, `tests/Feature/Payments/DepositServiceTest.php` (incl. the two new `ConnectionException` tests added for BUG-002) |
| Withdrawal — request validation, reject path, markFailed path | Full lifecycle driven for real: request → admin reject (amount released back to winnings balance) and request → approve → processing → markFailed (amount released back), both verified against the wallet ledger down to the exact coin amount | `tests/Feature/Withdrawal/WithdrawalApiTest.php`, `tests/Feature/Withdrawal/WithdrawalServiceTest.php`, `tests/Feature/Withdrawal/AdminWithdrawalUiTest.php` |

Every flow above returns the same response shape the mobile
`src/api/*.ts` modules and `src/types.ts` expect, and in the one case
where a real failure was produced (BUG-002), the mobile-side
`ApiError` handling in `deposit.tsx` was confirmed by static read to
already do the right thing (`err instanceof ApiError ? err.message :
...`) once the backend returned a proper `422` instead of a raw trace.

### (b) Static code review findings (mobile/app + mobile/src)

All 16 mobile screen/component files were read in full
(`app/_layout.tsx`, `app/deposit.tsx`, `app/index.tsx`,
`app/leaderboard.tsx`, `app/legal.tsx`, `app/login.tsx`,
`app/notifications.tsx`, `app/settings.tsx`, `app/tournament/[id].tsx`,
`app/wallet.tsx`, `app/withdraw.tsx`,
`src/auth/AuthContext.tsx`, `src/components/BrandLogo.tsx`,
`src/components/CoinAsset.tsx`, `src/components/LegalAcceptanceGate.tsx`,
`src/theme/ThemeContext.tsx`, `src/theme/icons.tsx`), checking each for
layout/sizing fragility (the RN `StyleSheet` layer has no CSS-cascade
equivalent of BUG-001/BUG-004, since there is no author-stylesheet
specificity or `table-layout:auto` in React Native — none found),
missing error/empty/loading states, accessibility basics, and
business-rule/API-contract consistency with the backend.

- **BUG-005 found and fixed:** `deposit.tsx`, `withdraw.tsx`, and
  `leaderboard.tsx` all called their data-loading functions with no
  `try`/`catch`, unlike the other four data-driven screens
  (`index.tsx`, `wallet.tsx`, `notifications.tsx`,
  `tournament/[id].tsx`), which handle this correctly. Any API
  failure (expired token, network blip, backend error) left those
  three screens permanently stuck on a loading/refreshing spinner with
  no error message and no way to retry. Fixed by adding the same
  `try`/`catch` + error-state pattern already used correctly
  elsewhere in the app; see **BUG-005** in `BUG_REPORT.md` for full
  detail. Confirmed via `npx tsc --noEmit` (clean) and a live `401`
  response from the real backend proving the exact failure path these
  `catch` blocks now handle.
- No raw `₹` symbols or ad-hoc coin icons found outside
  `CoinAsset` — confirmed by running the project's own
  `node scripts/check-no-raw-currency.js`, which scanned 28 files
  clean.
- Wallet/deposit/withdrawal field usage (`total_balance`,
  `deposit_balance`, `withdrawable_balance`) matches
  `WalletResource.php` exactly — no field-name drift between backend
  and mobile types.
- `AuthContext.tsx`, `tournament/[id].tsx`,
  `LegalAcceptanceGate.tsx`, and `legal.tsx` all handle their async
  calls safely (try/catch/finally or `.finally()`), so the auth
  bootstrap, join flow, and legal-acceptance gate cannot strand the
  user on an infinite spinner.
- No other real defects found. (Minor, non-blocking observations not
  worth a bug entry: `login.tsx`'s `TextInput`s don't set an explicit
  placeholder color, relying on the platform default, which is legible
  against the app's dark background in both the iOS and Android
  default cases but wasn't verified pixel-for-pixel without a real
  device.)

### (c) What this does NOT cover

Consistent with ENV-001: real device rendering, touch interaction,
keyboard behavior and avoidance, scrolling physics, safe-area insets
on real screen geometries, actual Android permission dialogs (push
notifications, camera/storage if ever added), font-theme rendering
with real device fonts, or any visual screenshot of the running app.
No claim of visual coverage is made for the mobile app — only the API
contract and the static source were verified.

## 6. Bugs found — summary

| ID | Severity | Area | Status |
|---|---|---|---|
| ENV-001 | Blocker (environment) | Android real-device testing | Not fixable in this sandbox — documented, substitute coverage used |
| BUG-001 | Major | Admin nav, every page | Fixed, verified |
| BUG-002 | Critical | Deposit gateway error handling | Fixed, verified |
| BUG-003 | Critical | Staff login landing page | Fixed, verified |
| BUG-004 | Minor | Table column padding (2 admin screens) | Fixed, verified |
| BUG-005 | Major | Mobile: 3 screens hang on API failure | Fixed, verified |

5 real, previously-undiscovered defects were found through actual live
interaction with the running application and fixed with minimal,
targeted changes — none by weakening a security or business rule. The
full backend suite was re-run clean after every single fix (ending at
365/365), and the mobile `tsc --noEmit` check is clean after BUG-005.

## 7. Final checks before sign-off

| Check | Result |
|---|---|
| `php artisan test --compact` (final run) | ✅ 365/365, 863 assertions |
| `vendor/bin/pint --test --format agent` (final run) | ✅ clean |
| `npx tsc --noEmit` (mobile, final run) | ✅ clean |
| `node scripts/check-no-raw-currency.js` | ✅ 28 files scanned, clean |
| `git status`/`git diff` reviewed for scope and secrets before commit | ✅ — no `.env`, credentials, or payment keys in the diff |

## 8. Honest coverage statement

**What was actually, visually inspected by opening it in a real
browser and looking at it:** every reachable Admin panel route (super-
admin), the Staff panel under 3 different permission configurations,
every state of the withdrawal lifecycle, a result-revision flow, and
every validation/empty/success state captured in `SCREENSHOT_INDEX.md`
— 43 Admin screenshots, 5 Staff screenshots, 8 E2E-flow screenshots,
and 7 bug-evidence captures, 63 in total.

**What was not visually inspected:** the Android app, in any form —
no real device, emulator, or even Expo-web substitute was available
(see ENV-001). Its coverage is API-level (real HTTP against the real
backend) and a full static source read-through (§5), not visual.

**What this audit does not claim:** production readiness from
passing automated tests alone. The 365 automated tests passing is one
signal among several in this report, not a substitute for the manual
walkthroughs above — which is why this audit was performed as a
manual pass in the first place, and why the mobile gap is reported as
a gap rather than papered over with test-suite numbers.

**Remaining risks for the project owner to weigh before going live:**
1. The Android app has never been run on a real device or emulator in
   this project's history as far as this audit could verify — the
   first real-device run should happen before any production release,
   following `docs/mobile-release.md` and this report's mobile test
   plan.
2. The payment gateway (Alpha UPI) has never been tested against the
   real provider — only its documented contract shape (and one real
   failure mode, the unreachable-host case that produced BUG-002).
   `alphaupi.site` is outside this sandbox's network policy, so a
   genuine create-order/webhook/check-status round trip with real
   credentials has not been verified anywhere in this project's
   history as far as this audit could tell. Confirm this before
   accepting real deposits.
3. Firebase push notifications were implemented and unit-tested but,
   per the Phase 12 implementation notes, require the user's real
   Firebase project credentials to verify delivery end-to-end — not
   independently re-verified in this QA pass.
4. The Risk Alerts admin view was only seen in its empty state in this
   session (no risk-alert-triggering scenario happened to occur during
   real usage) — its triggers are unit-tested (Phase 16) but a staff
   member has not been shown a populated Risk Alerts screen by this
   audit specifically.
5. This audit ran against a single-process `php artisan serve` dev
   server and a freshly seeded database — it does not substitute for a
   staging-environment load/concurrency test under production-like
   traffic, though the existing concurrency test suite (last-slot
   booking races, webhook races) does cover the logic-correctness side
   of that concern.
