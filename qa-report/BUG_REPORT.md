# Mini Max — Bug Report

Generated during the final end-to-end QA audit. Each entry: ID,
severity, repro steps, expected vs. actual, evidence, root cause, fix,
verification.

Severity scale: **Blocker** (prevents further testing of a whole area,
not necessarily a code defect), **Critical** (money/security/data
integrity), **Major** (broken functionality, no safe workaround),
**Minor** (cosmetic/UX), **Note** (observation, not a defect).

---

## ENV-001 — No real Android emulator available in this sandbox

- **Severity:** Blocker (environment, not a code defect)
- **Area:** Android Player App (QA task section 1)
- **Details:** This session runs in a cloud container with no Android
  SDK, no `adb`/`emulator`/`avdmanager` tooling, and no `/dev/kvm`
  (hardware virtualization) — Android emulation is not merely
  unconfigured, it is infeasible here (software-only ARM/x86 emulation
  without KVM is unusably slow to nonexistent in a container). No
  physical or remote device is reachable from this session either.
  `react-native-web` (which would let the app run in the pre-installed
  Chromium via Expo web) was never installed in this project — adding
  it now would both violate "don't change dependencies without
  approval" and still not constitute real Android testing.
- **Substitute coverage used instead (see TEST_RESULTS.md for
  detail):** every mobile-facing REST API flow exercised for real over
  HTTP (the actual network boundary the app's UI calls into), plus a
  full static code read-through of every `mobile/app/*.tsx` screen and
  shared component for layout/style/accessibility/error-and-empty-state
  logic.
- **What this does NOT cover:** real device rendering, touch
  interaction, keyboard behavior, scrolling physics, safe-area insets
  on real screen geometries, actual Android permission dialogs, or any
  visual screenshot of the running app. No Android screenshots exist in
  `qa-report/screenshots/android/` for this reason — producing them
  would require either real tooling this environment doesn't have, or
  fabricating them, and the task instructions explicitly rule out
  claiming coverage that wasn't actually performed.
- **Recommended next step for the project owner:** run
  `docs/mobile-release.md`'s build steps and this audit's mobile test
  plan (see TEST_RESULTS.md) against a real device or Android
  Studio emulator on a machine with KVM — e.g. via Claude Code's Remote
  Control connected to a local machine, or simply running the existing
  dev build locally.

---

## BUG-001 — Admin nav "Admin" label overlaps the "Tournaments" link

- **Severity:** Major (visual defect on every single admin/staff page —
  the shared nav renders on literally every authenticated route)
- **Area:** Admin/Staff panel, shared layout (`layouts/admin.blade.php`)
- **Repro:** Log into the Admin panel, look at the top-left brand link.
- **Expected:** The logo mark and "Admin" text render side by side with
  normal spacing, followed by the "Tournaments" nav link.
- **Actual:** The "Admin" text visually overlapped the "Tournaments"
  link's flag icon by ~10px — confirmed via DOM measurement
  (`element.scrollWidth` 99px vs. `clientWidth` 65px on the brand
  `<a>`, a 34px discrepancy exactly matching the logo image's intended
  width).
- **Evidence:** `qa-report/screenshots/bugs/bug-001-before.png` (zoomed
  crop) vs. `qa-report/screenshots/admin/23-settings.png` (after fix,
  same page re-captured).
- **Root cause:** `app/View/Components/LogoAsset`/
  `components/logo-asset.blade.php` sized the `<img>` only via HTML
  `width`/`height` attributes. Tailwind's Preflight applies `img {
  height: auto }` as an author-stylesheet rule, which outranks the
  HTML attribute presentation hints — so the image's actual CSS box
  was governed by `height: auto` plus flex auto-sizing rather than a
  fixed size. Nested one flex container inside another (the brand
  `<a>` is itself `display: flex` and also a flex item of the nav's
  outer flex row), this produced a flex-basis/content-size mismatch:
  the browser's layout pass for the outer flex row computed too little
  space for the logo+text item, so content with the text rendered at
  full size regardless, overflowing (`overflow: visible` by default)
  into the next item 24px away.
- **Fix:** `logo-asset.blade.php` now also sets the image's size via an
  inline `style="width:…px; height:…px"` (inline styles outrank
  class-based author rules in CSS specificity, so it can no longer be
  overridden by `height: auto`) and adds `shrink-0` so this flex item
  is never a shrink candidate. `layouts/admin.blade.php`'s brand link
  also now wraps "Admin" in an explicit `<span>` rather than a bare
  text node immediately adjacent to the image, removing an incidental
  stray-whitespace text node as a secondary cleanup (harmless on its
  own, but worth not leaving in place).
- **Files changed:** `backend/resources/views/components/logo-asset.blade.php`,
  `backend/resources/views/components/layouts/admin.blade.php`.
- **Verification:** Re-measured in the same browser session after the
  fix — `scrollWidth` now equals `clientWidth` (99px = 99px, zero
  overflow) and the gap to the next nav link is a clean 24px, matching
  `gap-6`. Visually confirmed via a fresh screenshot (nav renders
  correctly with proper spacing). Full backend test suite (360/360) and
  Pint re-run clean after the change, since this is a shared layout
  used on every admin/staff page.

---

## BUG-002 — A deposit-gateway connection failure leaked a raw 500/stack trace

- **Severity:** Critical (information disclosure risk if `APP_DEBUG`
  were ever left on in production; broken player-facing error handling
  regardless)
- **Area:** Backend, deposits (`App\Domain\Payments`)
- **Repro:** `POST /api/v1/deposits` while the configured payment
  gateway host is unreachable (DNS failure, connection refused, proxy
  block, timeout before any HTTP response — exactly what happened for
  real in this sandbox, since `alphaupi.site` is outside the network
  policy here).
- **Expected:** A clean `422` with a player-facing message, the same
  as the already-handled case where the gateway responds with a bad
  HTTP status or malformed body.
- **Actual:** An uncaught `Illuminate\Http\Client\ConnectionException`
  propagated all the way to Laravel's default handler, returning a raw
  500 response with the full exception trace (file paths, line
  numbers, the whole call stack) in the JSON body — observed for real
  in this sandbox, not synthesized.
- **Evidence:** `qa-report/screenshots/bugs/bug-002-before-raw-trace.txt`
  (the actual captured response body) and
  `qa-report/screenshots/bugs/bug-002-after-clean-error.txt` (same
  call, after the fix).
- **Root cause:** `AlphaUpiAdapter::createOrder()`/`checkStatus()`
  called `Http::...->post()` directly. A transport-level failure
  (`ConnectionException`) is thrown *before* any `Response` object
  exists, so it never reached `decodeOrFail()` — the method that
  already translates a bad *application-level* response (non-2xx
  status, malformed body) into the adapter's own `PaymentGatewayException`.
  `DepositService::initiate()` only caught `PaymentGatewayException`,
  so the transport-level exception type sailed past it uncaught. Worse,
  `DepositService::reconcile()` — what the mobile app polls after a
  player returns from the payment browser — had **no catch at all**,
  not even for `PaymentGatewayException`, so the exact same failure
  mode there would break the player's deposit-status polling outright
  instead of just returning the deposit's last-known (still-pending)
  state.
- **Fix:**
  - `AlphaUpiAdapter`: both gateway calls now go through a small
    `post()` helper that catches `ConnectionException` and re-throws it
    as `PaymentGatewayException`, unifying every "the gateway didn't
    work" failure mode (transport or application level) under the one
    exception type callers already handle.
  - `DepositService::reconcile()`: now catches `PaymentGatewayException`
    around the `checkStatus()` call, logs a warning, and returns the
    order's current state unchanged — safe because the gateway's own
    webhook delivery (not `reconcile()`) is the primary way a deposit
    gets marked paid; a poll that can't get a fresher status just
    means the player sees "still pending" instead of a crash, and the
    next poll or the webhook can still resolve it.
- **Files changed:** `backend/app/Domain/Payments/AlphaUpiAdapter.php`,
  `backend/app/Domain/Payments/DepositService.php`.
- **Verification:** Re-ran the exact failing request against the live
  server afterward — now returns a clean `422` with a readable message
  instead of a 500/stack trace (see evidence files above). Added
  `test_a_gateway_connection_failure_marks_the_order_failed_and_raises_a_validation_error`
  and `test_reconcile_returns_the_orders_current_state_when_the_gateway_is_unreachable`
  to `tests/Feature/Payments/DepositServiceTest.php` (both simulate the
  connection failure via `Http::fake()` throwing a `ConnectionException`,
  the standard Laravel way to test this without a real network call).
  Full payments suite (32/32) and full backend suite (362/362) pass;
  Pint clean.

---

## BUG-003 — A staff account missing `tournaments.view` lands on a bare 403 immediately after login, with no way to navigate anywhere else

- **Severity:** Critical (breaks the app entirely for a realistic,
  legitimately-configured account — any permission preset that
  doesn't include `tournaments.view`, e.g. a Legal-only or
  Notifications-only role)
- **Area:** Admin/Staff panel, login flow + shared nav
- **Repro:** Create a staff account whose preset has some permissions
  but not `tournaments.view` (e.g. just `legal.manage`). Log in.
- **Expected:** Successful login lands the staff member somewhere they
  can actually use — at minimum, a page they're allowed to see.
- **Actual:** A bare, unstyled Laravel 403 page ("Missing required
  permission: tournaments.view") with no app chrome, no nav, and no
  link back anywhere — confirmed live in this sandbox with a staff
  account holding only `legal.manage` + `notifications.send`.
- **Evidence:** `qa-report/screenshots/bugs/bug-003-after-home-page.png`
  shows the fixed landing page for a zero-permission account (the
  worst case); `qa-report/screenshots/staff/02-limited-permissions-home.png`
  shows the fixed nav + home cards for the legal/notifications-only
  account, listing exactly the two sections it can use.
- **Root cause, two compounding issues:**
  1. Both the post-login redirect (`Login::login()`) and the bare
     `/admin` → redirect (`routes/admin.php`) were hardcoded to
     `admin.tournaments.index`, which requires `tournaments.view` —
     with no awareness of what the logging-in account can actually
     see.
  2. The nav's "Tournaments" and "Notifications" links were rendered
     unconditionally — every *other* nav item is wrapped in
     `@if (auth('staff')->user()->hasPermission(...))`, but these two
     were not, so even staff members who got past login would see
     dead links to sections they can't open.
- **Fix:**
  - New `App\Livewire\Admin\Home` component at `/admin/home`
    (`admin.home`), the one route in the whole panel deliberately
    carrying no `permission:` middleware — reachable by any
    authenticated staff account. It lists only the sections the
    logged-in account actually has permission for (same checks as the
    nav, same order), or a friendly "contact an administrator" message
    if the account has none at all.
  - `Login::login()` and the bare `/admin` redirect now both target
    `admin.home` instead of `admin.tournaments.index`.
  - `layouts/admin.blade.php`: the Tournaments and Notifications nav
    links are now wrapped in the same `hasPermission()` checks as every
    other item, and the brand/logo link now points at `admin.home`
    (a sensible "go home" target, not a possibly-restricted page).
- **Files changed:**
  `backend/app/Livewire/Admin/Home.php` (new),
  `backend/resources/views/livewire/admin/home.blade.php` (new),
  `backend/routes/admin.php`,
  `backend/app/Livewire/Admin/Login.php`,
  `backend/resources/views/components/layouts/admin.blade.php`.
- **Verification:** Re-tested live with two accounts — one with zero
  permissions (shows the friendly empty-state message) and one with
  only `legal.manage`/`notifications.send` (shows exactly those two
  nav links and home cards, nothing else) — both now land on
  `/admin/home` with zero failed requests, confirmed via Playwright
  network-response logging. Direct navigation to a still-restricted
  route (`/admin/withdrawals`) correctly still 403s — the fix only
  changes where an account *lands*, not what it's allowed to reach.
  Added `test_logging_in_without_tournaments_view_redirects_to_home_not_tournaments`,
  `test_a_staff_user_without_tournaments_view_can_load_home_and_only_sees_permitted_sections`,
  and `test_the_home_page_shows_a_friendly_message_for_a_staff_user_with_no_permissions`
  to `tests/Feature/StaffLoginTest.php`. Full backend suite (365/365)
  and Pint clean.

---

## BUG-004 — Table columns render with zero gap when cell content is long

- **Severity:** Minor (cosmetic/readability only, no functional or
  security impact — but affects 3 tables an admin/staff reads
  regularly, including the player wallet-ledger view used to
  investigate disputes)
- **Area:** Admin panel, `user-show.blade.php` (2 tables) and
  `staff-salary-settlement-show.blade.php` (1 table)
- **Repro:** Open a player's detail page (`/admin/users/{id}`) for a
  player with a wallet-ledger entry whose `reason` text is long enough
  to stress the browser's `table-layout:auto` column-width algorithm
  (e.g. an admin wallet adjustment with a multi-word reason). Look at
  the "Recent wallet activity" table's header and data row.
- **Expected:** A clean visual gap between every column, matching the
  `px-4 py-3` padding convention used by every other admin table (e.g.
  `withdrawal-list.blade.php`).
- **Actual:** The "Amount" and "Reason" columns rendered with zero gap
  between them — "Amount Reason" in the header and the data cells ran
  together with no visible separation — confirmed live after adding a
  200.00 wallet adjustment with the reason "QA audit: fund more
  winnings for withdrawal reject/fail path testing" to a test player's
  account.
- **Evidence:** `qa-report/screenshots/bugs/bug-004-before.png` (zoomed
  crop showing "Amount Reason" jammed together with no gap) vs.
  `qa-report/screenshots/bugs/bug-004-after.png` (same table, same
  data, after the fix — clean gaps between every column).
- **Root cause:** These 3 tables' `<th>`/`<td>` cells used only
  `py-2` with no horizontal padding class at all, unlike every other
  admin table in the app, which uses `px-4 py-3`. With
  `table-layout: auto` (the Tailwind/browser default), a column's
  rendered width is driven by its content, and with zero horizontal
  padding, two adjacent cells whose content doesn't itself end in
  whitespace (e.g. a numeric amount immediately followed by a text
  cell) can render with no visible gap between them even though they
  are in separate `<td>`s.
- **Fix:** Added `px-4` to every `<th>` and `<td>` class (including the
  `colspan` empty-state cells) in all 3 affected tables, matching the
  app-wide `px-4 py-3`-family convention. Grepped the rest of the admin
  Blade views for the same `py-\d` (no `px-`) anti-pattern on table
  cells after finding the first instance — these were the only 3
  tables affected.
- **Files changed:**
  `backend/resources/views/livewire/admin/user-show.blade.php`
  (both tables), `backend/resources/views/livewire/admin/staff-salary-settlement-show.blade.php`.
- **Verification:** Re-captured the user-detail page screenshot after
  the fix and confirmed, via direct visual comparison, consistent gaps
  between all 5 columns of the wallet-activity table and all 3 columns
  of the tournament-entries table. Full backend suite (365/365) and
  Pint re-run clean; no test previously asserted on this markup so no
  existing test needed updating.

---

## BUG-005 — Three mobile screens hang on a permanent loading spinner (or a stuck pull-to-refresh spinner) on any API failure

- **Severity:** Major (no safe workaround from inside the screen — a
  transient network blip, an expired/invalid auth token, or any
  backend error permanently strands the player on that screen with no
  way to retry other than leaving and re-entering it)
- **Area:** Mobile app, `app/deposit.tsx`, `app/withdraw.tsx`,
  `app/leaderboard.tsx` (found during the static code review that
  substitutes for real-device testing per ENV-001)
- **Repro:** On any of the three screens, trigger an API failure on
  the very first load — e.g. an expired/invalid Sanctum token (easy to
  reproduce for real: `curl -H "Authorization: Bearer invalid" .../api/v1/wallet`
  returns a clean `401 {"message":"Unauthenticated."}`) or any network
  failure.
- **Expected:** The screen shows an error message (as `index.tsx`,
  `wallet.tsx`, `notifications.tsx`, and `tournament/[id].tsx` already
  correctly do for the same failure) and lets the player retry via
  pull-to-refresh or, for leaderboard.tsx specifically, a tap-to-retry
  affordance.
- **Actual:** All three screens' `load()` functions called their API
  functions directly with **no `try`/`catch`**, unlike every other
  screen in the app. The call sites were `setLoading(true); await
  load(); setLoading(false);` (initial load) and `setRefreshing(true);
  await load(); setRefreshing(false);` (pull-to-refresh) — with no
  surrounding `try`/`catch` either. When `load()` threw (the
  `ApiError` that `src/api/client.ts`'s `apiRequest()` throws on any
  non-2xx response, or a plain network-level rejection), the thrown
  error propagated out of the `async` IIFE/handler as an unhandled
  rejection, and the line that resets `loading`/`refreshing` back to
  `false` was skipped — leaving the screen showing `ActivityIndicator`
  forever (deposit/withdraw: list renders beneath a stuck top spinner
  since those don't gate the whole screen on `!data`; leaderboard.tsx
  additionally gated its entire `FlatList` behind `loading ||
  !leaderboard`, so a failed first load left it on a bare spinner with
  no list, no error, and no retry control at all, indefinitely).
- **Root cause:** `index.tsx`, `wallet.tsx`, `notifications.tsx`, and
  `tournament/[id].tsx` all wrap their `load()` bodies in
  `try { ... } catch { setError(...) }`, which is what lets
  `setLoading(false)` run unconditionally afterward. `deposit.tsx`,
  `withdraw.tsx`, and `leaderboard.tsx` were written without that
  wrapper — an inconsistency between screens built at different times,
  not a deliberate choice.
- **Fix:** Added the same `try`/`catch` wrapper to all three `load()`
  functions, each setting a dedicated error-message state
  (`loadError` in deposit.tsx/withdraw.tsx, since both already use
  `error` for their submit-form's own error message and reusing it
  would have shown a list-load failure inside the form instead; a
  plain `error` in leaderboard.tsx, which has no competing use). The
  load-error message is surfaced via each `FlatList`'s
  `ListEmptyComponent` (deposit.tsx, withdraw.tsx) so pull-to-refresh
  keeps working to retry. `leaderboard.tsx` additionally needed its
  render gate changed from `loading || !leaderboard` to a three-way
  branch (`loading` → spinner; `!leaderboard` after a failed load → a
  tappable retry message; otherwise → the list), since unlike the
  other two screens its entire body was conditionally hidden behind
  having data, so a `ListEmptyComponent` alone wouldn't have been
  reachable on a failed first load.
- **Files changed:** `mobile/app/deposit.tsx`, `mobile/app/withdraw.tsx`,
  `mobile/app/leaderboard.tsx`.
- **Verification:** `npx tsc --noEmit` passes clean with no errors.
  Confirmed live against the real running backend that the underlying
  API calls do throw exactly the `ApiError` these `catch` blocks now
  handle (a request with an invalid bearer token against the real
  `/api/v1/wallet` endpoint returns a clean `401
  {"message":"Unauthenticated."}`, which `apiRequest()` turns into a
  thrown `ApiError` — previously unhandled by these three screens,
  now caught). No backend files were touched, so no backend test
  re-run was needed; this is a mobile-only, UI-state-handling fix with
  no business-rule or validation change. Could not visually confirm on
  a real device per ENV-001 — verification is static (re-read code,
  confirm the `try`/`catch` and render-gate logic match the already-
  correct screens) plus the live API check above.

---
