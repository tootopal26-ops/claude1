# Mini Max app — UI/UX redesign, navigation and motion: report

Companion to [`MOBILE_REDESIGN_AUDIT.md`](MOBILE_REDESIGN_AUDIT.md) (the audit and plan written first).
Screenshots and the per-run check results are in [`app-web-render/`](app-web-render/README.md).
The captures there were refreshed by the later UI repair pass; see [`MOBILE_UI_REPAIR_REPORT.md`](MOBILE_UI_REPAIR_REPORT.md). The run figures below (66 captures, 46 checks) are from the redesign run.

> **Platform honesty.** Everything below was verified on the real app code rendered by
> react-native-web in headless Chromium against the real local backend — **not** on an Android
> emulator or device. This container has no hardware virtualisation (`/dev/kvm` is absent) and its
> network policy blocks the Android SDK host `dl.google.com`, so an emulator cannot run here.
> Nothing in this report claims Android behaviour or Android performance.

## 1. Screens discovered and the route map after the redesign

| Route | Screen | Change |
|---|---|---|
| (boot) | Branded boot screen | **new** — real logo at the splash size; Retry when the server is unreachable |
| `/login` | Sign in · Create account | redesigned |
| `/` (tab) | Home | rebuilt in the specified order |
| `/leaderboard` (tab) | Leaderboard | rebuilt: hero, period tabs, podium, ranks 4–100; lock state kept |
| `/profile` (tab) | Profile | rebuilt: avatar, stats, menu, logout confirmation |
| `/tournaments?status=` | Tournament list (all games) | **new**, shared with `/game/[id]` |
| `/game/[id]` | One game's tournaments | rebuilt on the shared list |
| `/tournament/[id]` | Tournament details | rebuilt: credentials with copy, result, rules, join |
| `/tournament/[id]/results` | Published results | **new** |
| `/account` | My Profile (details + signed-in devices) | **new** (uses existing `/auth/me` and sessions APIs) |
| `/wallet`, `/deposit`, `/withdraw` | Wallet and money flows | redesigned; logic unchanged |
| `/notifications` | Notifications | redesigned; tournament notifications open the tournament |
| `/settings`, `/legal` | Themes, legal texts | kept |
| (sheets) | Rules, Join, Redeem Code, Unlock, Log out, Sign out device | all on one shared `Sheet` |

Navigation: one root stack (pushes slide in from the right) with a `(tabs)` group holding exactly
**Leaderboard · Home · Profile** behind one floating bottom bar. Home is the anchor: the app opens
there and Back from the other two tabs returns there before leaving. Signed-out players can reach
only sign-in and the legal texts; the stack's guard (not each screen) enforces it.

## 2. Design system

- **Tokens** (`mobile/src/theme/tokens.ts`): spacing 4/8/12/16/20/24/32, radii 8/12/16/20/pill,
  component sizes (touch 44, buttons 48/40, nav 64, avatars), a type scale, rank ring colours
  (gold/silver/bronze; ranks 4–100 use the success green), and a 560-dp content cap. Colours map
  onto the existing seven themes — nothing conflicts with the Admin/Staff palette.
- **Motion** (`mobile/src/theme/motion.ts`): durations instant 120 · small 160 · card 200 ·
  screen 280 · modal 240 · sheet 300 · content 320 · hero 750 · ambient 2400 ms; ease-out for
  entering, ease-in for leaving, ease-in-out between states; press scales 0.97 / 0.98 / 0.95; one
  app-wide reduced-motion subscription.
- **Components**: `PressableScale`, `Button` (primary/secondary/text/danger; loading, disabled),
  `TextField` (labels, field errors, hints, password show/hide), `Avatar` (SVG ring + soft glow,
  **no shadow**, optional slow pulse, initials fallback), `HeroCarousel`, `SegmentedControl`,
  `Sheet`, `Skeleton` / `EmptyState` / `ErrorState`, `BottomNav`, `WalletPill`, `HomeHeader`,
  `ShortcutCards`, `GameCard`, `TournamentCard`, `RulesSheet`, `JoinSheet`, `RoomCredentials`,
  `Podium`, `RankRow`, `StatCard`, `MenuRow`, `BootScreen`.

### Motion as implemented

| Spec item | Implementation |
|---|---|
| Tab change: fade + ~4 dp | custom scene interpolator, 180 ms ease-out; plain fade under reduced motion |
| Nested push from the right | native stack `slide_from_right`; `fade` under reduced motion |
| Bottom bar | fades in once on launch; active indicator 160 ms; press 0.95; never moves between tabs |
| Avatar ring pulse | 2.4 s glow-opacity loop on the Home/Profile avatar; off under reduced motion |
| Hero carousel | single 5 s timer; 750 ms right-to-left change; edge softening only during the change; pauses off-screen, in background, while swiping and under reduced motion |
| Card / shortcut press | 0.98 scale |
| Leaderboard period change | content dims and returns in 120 / 200 ms; podium enters with an 8 dp lift + fade |
| Sheets and dialogs | backdrop fade, 16–40 dp upward move; closing plays in reverse before unmount |
| Copy confirmation | button turns into "Copied" with a 160 ms fade, resets after 1.6 s |
| Skeletons | slow 1.4 s opacity pulse; static under reduced motion |

## 3. Screens redesigned

Login/registration, boot, Home, Leaderboard (incl. locked state), Profile, My Profile, tournament
list, tournament card, tournament details, join flow, rules, room credentials, results, wallet,
add coins, withdraw, notifications, redeem code, section unlock and every confirmation sheet.
Settings and Legal keep their existing layout under the new navigation.

**Not a regression, a move:** the paid-section (Leaderboard) unlock card used to sit on Home. The
specification fixes Home's exact order, so the unlock now lives where the locked content is — the
Leaderboard tab shows the banners and the unlock card while locked. The unlock flow itself is
unchanged.

## 4. Flows verified (latest run: 46/46 automated checks passed)

The full table with evidence is in [`app-web-render/README.md`](app-web-render/README.md#automated-regression-checks-this-run). Highlights:

- signed-out `/` shows Sign in; empty and wrong-password sign-in show field-level and server messages;
- a network failure while checking the saved session keeps the session and Retry recovers;
- Home: wallet pill = server `total_balance`; shortcut counts = `meta.counts`; each game card =
  its `upcoming_tournaments_count`; order header → hero → shortcuts → games; exactly three nav items;
  bell = real unread count; the hero advances after ~5 s, and does **not** under reduced motion;
- join (real, against the QA backend): no deduction before confirmation; exactly the 40-coin fee
  after it; the list then says Joined; a second join is refused by the server (422);
- room credentials only after release; Copy puts the exact room ID on the clipboard;
- results list every published row; a full tournament's action is disabled;
- leaderboard podium order 2 · 1 · 3, every server entry shown, first place shows server earnings;
  re-tapping the active tab pushes nothing; history Back returns to the list;
- Profile stats = `/profile/stats`; logout asks first and Cancel keeps the session;
- wallet = server balance (same figure as Home); tournament notifications open the tournament;
- no horizontal overflow on 66 captures at 360, 390, 430 and 768 dp; no uncaught JS errors;
- bottom bar stays within the content column on tablets; the greeting does not truncate at 360 dp
  with normal balances; an 8-digit balance is never cut;
- axe-core WCAG 2.0/2.1 A + AA: 0 violations on Home, tournaments, live tournament, leaderboard,
  profile and wallet.

## 5. Bugs fixed

From the audit (pre-redesign; evidence in `app-web-render/before-redesign/`):
1. Login form pushed off the first screen (logo kept its 1000-px intrinsic height).
2. Tournament cards and profile rows lost their styles (`Link asChild` + function `style`).
3. Wallet stuck on a spinner after a failed load — now an error with Try again.
4. History rows clipped their status chip on long reasons.
5. Wallet tiles overflowed with 8-digit balances.
6. Home hid the balance while tournaments loaded — every Home section now loads on its own.

Found and fixed during this redesign's QA:
7. A network failure at start-up signed the player out — now only a server 401 does; other
   failures show the boot screen with Retry. A token revoked elsewhere now ends the local session
   cleanly instead of failing on every screen.
8. A signed-out visit to `/` fell back to the Legal screen — explicit guard redirects added.
9. Selected / disabled / busy / checked states were invisible to the web accessibility tree
   (`accessibilityState` is ignored by react-native-web) — switched to the `aria-*` props React
   Native also supports natively.
10. Buttons given `flex: 1` did not stretch (the style landed inside the pressable).
11. Tournament cards nested their action buttons inside the card's own button (nested
    interactive controls) — the details area and the actions are now siblings.
12. Pushing a tab path (`/leaderboard`) from a banner or unlock dialog could stack a second tab
    navigator — tab paths now navigate.
13. Withdraw fetched its own copy of the balance — it now reads the one shared wallet.
14. Ledger labels did not match the backend's real entry types (withdrawal reserve/release and
    tournament prize had no labels); a reason identical to its label is no longer repeated.
15. Bottom bar stretched the full width on tablets; "Welcome Back" truncated at 360 dp; the wallet
    pill could cut an 8-digit balance; "1 kills".

## 6. Backend changes and dependencies

Added (earlier commit `8eaac7d`, tested): tournament `status` filter + per-tab `meta.counts`,
`joining_open` / `join_closes_at` / `game` on tournaments, `upcoming_tournaments_count` on games,
`GET /tournaments/{id}/results`, `GET /profile/stats`, leaderboard top 100 with `user_id` and
per-rank `prize`, Home banner placement with validated in-app/https links and Admin › Home Banners.
This commit: `/auth/me`, login and register return the player's own `email`, `state` and
`member_since` (used only for the signed-in player; covered by a feature test).

**Not built — needs a backend feature first** (the app omits them instead of faking them):
- video banners (no video storage or playback support);
- map name and booyah reward on tournaments (no such fields);
- profile picture upload (no avatar storage or upload endpoint; initials are shown);
- Support (no support/ticket feature, so no Support row);
- editing account details (no update endpoint; My Profile is read-only);
- "mark all notifications read" (only per-notification read exists).

**New mobile dependency:** `expo-clipboard ~58.0.3` (the Expo SDK 58 version), required by the
spec's copy buttons for room ID and password. No other dependency was added or changed.

## 7. Build, type, lint and test results

| Check | Result |
|---|---|
| `npx tsc --noEmit` (mobile) | clean |
| `npm run check:currency` (no raw ₹ / ad-hoc coin graphics) | clean, 80 files |
| `npx expo export --platform web` (scratch harness) | builds |
| ESLint (`npx expo lint`) | **not run** — the app has no ESLint configuration, and `expo lint` would install one (a dependency change) |
| `vendor/bin/pint --dirty --format agent` (backend) | passed |
| `php artisan test --compact` (backend) | **555 passed**, 4,799 assertions |
| Web-render regression sweep | 66 captures, 46/46 checks, 0 problems, axe 0 violations |
| Android build / emulator | **not run** — see section 9 |

## 8. Screenshots

[`qa-report/app-web-render/`](app-web-render/README.md): 66 captures (phone, small, large, tablet,
two colour themes, stress), 9 contact sheets, `captures.json` with every check. All are
react-native-web renders in Chromium and are labelled as such; none is presented as Android.

## 9. Could not be tested here

- **Anything on Android**: emulator boot, the native splash hand-off, real animation smoothness and
  frame rate, the hardware/gesture Back key (only browser history Back was exercised), the
  keyboard covering fields, TalkBack, haptics, status/navigation bar insets on real devices.
  Blockers: no `/dev/kvm` in this container, and the environment's network policy denies
  `dl.google.com` (Android SDK downloads).
- Push notification taps (needs a device and the Firebase project).
- The UPI payment hand-off in a real payment app (the deposit page opens the provider URL only).
- The native clipboard (copy was verified with the browser clipboard).

To see the redesign on Android, on a Windows machine with Android Studio and an emulator running:

```
cd mobile
npm install
npx expo run:android
```

or build an installable APK in the cloud: `npx eas-cli@latest build --platform android --profile preview`
(see `docs/mobile-release.md`). `expo-clipboard` is a native module that Expo Go may not match
for this SDK, so use `expo run:android` or the preview build rather than Expo Go.
