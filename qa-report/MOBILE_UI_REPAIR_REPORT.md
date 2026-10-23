# Mini Max app — UI repair and visual QA pass: report

This pass follows the redesign in [`MOBILE_REDESIGN_REPORT.md`](MOBILE_REDESIGN_REPORT.md). It covers the
"Complete UI repair, redesign and visual quality control" request and the two archives supplied with it
(`Ongoing_Upcoming_Completed_Gaming_Badges.zip`, `Glossy_Neon_Navigation_Icon_Set.zip`).
Captures and per-check evidence: [`app-web-render/`](app-web-render/README.md).

> **Platform honesty.** Everything here was checked on the real app code rendered by
> react-native-web in headless Chromium, against the real local Laravel backend with QA data.
> **No Android emulator or device ran.** The container has no `/dev/kvm`, and its network policy
> blocks the Android SDK host `dl.google.com`. No capture is presented as an Android screenshot.

Only the mobile app changed in this pass. The backend, API contracts, auth, tournament rules,
wallet maths and permissions are untouched, and no dependency was added or upgraded.

## 1. Files and components changed

| Area | Files | What changed |
|---|---|---|
| Design tokens | `mobile/src/theme/tokens.ts` | `ICON_COLORS` (gold, green, blue, sky, violet, slate, red, silver, bronze; each with main, deep and light shades), `ICON_SIZE` scale (14 / 16 / 20 / 22 / 24 / 28 / 30), `ASPECT` (hero, game, tournament = 16:9), `SIZE.headerControl` (42), podium avatar sizes. |
| Icon system | `mobile/src/theme/icons.tsx` | New filled, two-tone artwork for 23 icons: home, trophy, user, users, play, check-circle, calendar, clock, notifications, wallet, deposit, withdrawal, redeem, settings, lock, unlock, copy, file-text, tournaments, leaderboard, info, logout, image. Uses only `Path`, `Rect`, `Circle`, `G` and `LinearGradient` from react-native-svg: no filters, masks or `foreignObject`. Passing `color` still draws the single-colour glyph, which buttons use for legibility on solid fills. |
| Header | `src/components/home/HomeHeader.tsx`, `src/components/wallet/WalletPill.tsx` | The wallet is now a 42 dp rounded rectangle (radius 12), not a capsule. It shows the coin PNG and the server balance, and the figure is never truncated. The bell is a 42 dp rounded square with the filled gold bell. Its red dot appears only when the server's `unread_count` is above zero. |
| Shortcuts | `src/components/home/ShortcutCards.tsx`, `app/(tabs)/index.tsx` | Ongoing, Upcoming and Completed use the supplied badges, sized from the column width, with the server's tab counts under them. Each card opens the matching list filter. |
| Game cards | `src/components/cards/GameCard.tsx`, new `src/components/visual/BottomFade.tsx` | The title and the green dot with the upcoming count sit inside the bottom of the 16:9 art, over an SVG gradient fade. A game with no upcoming tournaments shows a grey dot and 0. |
| Bottom nav | `src/components/navigation/BottomNav.tsx` | Uses the supplied Leaderboard, Home and Profile artwork. The selected tab is full size and full opacity. The others are 88 % size at 72 % opacity, so they stay readable. |
| Profile | `app/(tabs)/profile.tsx` | Order: title, avatar ring, username, member since, stats, then Account (My Profile, Wallet, Notifications, Redeem Code, Settings, Terms and privacy). Log out comes last in a red-edged group and still asks for confirmation. |
| Leaderboard | `app/(tabs)/leaderboard.tsx` | A "Provisional standings" or "Final standings" chip replaces any "live" wording. Provisional standings refresh every 60 s, but only while the tab is focused and the app is in the foreground. |
| Settings | `app/settings.tsx` | Grouped glass cards. Colour and font pickers are radio groups with a tick on the selected option and a correct `aria-checked`. |
| Terms and privacy | `app/legal.tsx` | Loading skeleton, then version and published date, a Terms / Privacy switch and readable body text. **Bug fix:** a failed load used to say "Nothing published yet". It now shows an error with Try again, and the empty state appears only when the server really has no document. |
| Icon call sites | `app/account.tsx`, `app/notifications.tsx`, `app/tournament/[id]/index.tsx`, `src/components/{Button,SectionCard}.tsx`, `src/components/cards/MenuRow.tsx`, `src/components/carousel/HeroCarousel.tsx`, `src/components/states/States.tsx`, `src/components/tournament/{JoinSheet,RoomCredentials,TournamentCard}.tsx` | These now use the filled icons at token sizes. The join sheet labels each field "(required)" or "(optional)". Read notifications dim their icon. Unused theme variables were removed. |
| Asset pipeline | new `mobile/scripts/extract-checkerboard-icons.py`, `mobile/scripts/check-no-raw-currency.js` | The extraction script is described in section 3. `BottomFade.tsx` was added to the currency check's allow-list for inline SVG. It is a gradient only and contains no coin or currency imagery. |
| New assets | `mobile/assets/nav/{leaderboard,home,profile}.png`, `mobile/assets/badges/{ongoing,upcoming,completed}.png` | Cut from the supplied sheets. |

## 2. Visual problems fixed

- **Icons:** white outline glyphs are replaced by filled artwork with a consistent colour per category:
  gold for trophy, results, bell and copy; green for live, play and completed; blue for the calendar
  and documents; violet for user, wallet and redeem; slate for the gear; red for logout. The coin
  stays the existing PNG (`CoinAsset`) everywhere.
- **Home wallet:** the capsule is now a compact rounded rectangle, and the balance is never cut,
  even at 8 digits on a 360 dp screen.
- **Notification button:** now a rounded square that matches the wallet tile's height. The unread dot is real data.
- **Shortcut cards:** the supplied badges replace generic icons, and all three cards are the same size and aligned.
- **Game cards:** the title and count moved from under the card to inside its bottom edge, over a
  controlled fade. The count shows green, or grey for 0.
- **Tournament detail:** the artwork is a full-width 16:9 hero, measured by check R42.
- **Profile:** identity and stats come before the account rows, the rows are in the requested order,
  and Log out is last. At the scroll end, Log out clears the floating nav on every size tested (R43).
- **Leaderboard:** no misleading "live" label, and refresh no longer runs while the tab is in the
  background. The podium order (2 · 1 · 3), the gold, silver and bronze rings, the green rings for
  ranks 4 and below, and the aligned coin earnings carry over from the redesign and were re-verified.
- **Bottom nav:** inactive icons were too dim at 55 % opacity and are now at 72 %.
- **Terms and privacy:** a network failure is no longer passed off as "nothing published".

## 3. Supplied assets integrated

| Source archive | Used for | Notes |
|---|---|---|
| Glossy Neon Navigation Icon Set | Bottom-nav Leaderboard (trophy), Home (house) and Profile (avatar) | 144 × 144 PNGs, drawn at 30 dp. |
| Ongoing / Upcoming / Completed Gaming Badges | The three Home shortcut cards | 360 px wide PNGs. The labels are part of the artwork, and each badge also has an accessibility label. |

The supplied PNGs had no real transparency: they were RGB images with a grey-and-white
checkerboard painted in. `mobile/scripts/extract-checkerboard-icons.py` removes that background.
It flood-fills from the edges, tests enclosed pockets for the checker texture, unblends the
anti-aliased fringe, splits each sheet at its widest empty column gaps, and resizes with
premultiplied alpha. Nothing was redrawn or invented, and no emoji is used. If you have the
original transparent files or SVGs, they can replace these one for one.

## 4. Screens and states actually tested

All 46 states listed in the request were rendered and checked in the final run. There are 70
captures in total.

| Requested | Capture(s) | Requested | Capture(s) |
|---|---|---|---|
| 1–3 Sign in: normal, empty, wrong credentials | 01, 02, 03 | 24 Detail, upcoming | 25 |
| 4–5 Create account: normal, errors | 04, 05 | 25 Detail, ongoing | 19 (live, credentials released) |
| 6 Saved-session loading | 06 | 26 Detail, joined | 26 |
| 7 Server unreachable | 07 | 27 Game-specific list | 27 |
| 8–10 Home: normal, full height, carousel changing | 08, 09, 10 | 28–30 Leaderboard daily, weekly, monthly | 28–31 |
| 11–14 Lists: upcoming, full, ongoing, completed | 11, 12, 18, 21 | 31–32 Leaderboard locked, unlock confirm | 32, 33 |
| 15–18 Join: initial, missing names, confirm, success | 14, 15, 16, 17 | 33–34 Profile, logout confirm | 34, 35, 36 |
| 19–20 Room details, ID copied | 19, 20 | 35 Redeem modal | 37 (with the server's refusal) |
| 21 Rules sheet | 13 | 36–42 My Profile, Wallet, Add coins, Withdraw, Notifications, Settings, Terms | 38–44 |
| 22–23 Results published, pending | 22, 24 | 43–46 Wallet history failure, Home games failure, list failure, reduced motion | 45, 46, 47, 48 |

**Compact and other sizes:**
- 360 × 640: Home, Tournaments, Leaderboard, Profile and Profile scrolled to the end, plus three
  stress captures (8-digit balance on Home and Wallet, and a very long title with a 7-digit prize).
- 430 × 932 and 768 × 1024: the same four tabs.
- Theme captures: two colour themes.

Not every one of the 46 states was captured at 360 × 640. That size covers the four main tabs and
the stress cases only.

## 5. Checks run

| Check | Result |
|---|---|
| `npx tsc --noEmit` (mobile) | passed |
| `npm run check:currency` (no raw ₹; inline SVG only in allowed files) | passed, 81 files |
| Unused-locals scan (`tsc --noUnusedLocals`) | clean for this pass's files. Two older leftovers remain (`LegalAcceptanceGate.tsx` `setActiveIndex`, `States.tsx` `DURATION`). |
| Web-render regression sweep (Playwright, real backend) | **70 captures, 55/55 checks passed, 0 problems**, no uncaught JS errors, no horizontal overflow on any capture |
| New checks this pass | R38 wallet tile is a rounded rectangle (radius 12, height 42) · R39 nav uses the three supplied images · R40 Profile order, with Log out last · R41 menu icons are filled · R42 detail hero is full-width 16:9 · R43 Log out clears the nav at the scroll end at 360, 390, 430 and 768 |
| axe-core (WCAG 2.0/2.1 A + AA) on Home, Tournaments, live tournament, Leaderboard, Profile, Wallet | 0 violations |
| `php artisan test --compact` (backend, unchanged this pass; run to confirm nothing regressed) | **555 passed**, 4,799 assertions |
| ESLint / unit tests (mobile) | **not available**: the mobile project has no ESLint config and no test runner |
| `npx expo install --check` | **could not run**: the proxy blocks Expo's API (`HTTP Proxy Network Error: Forbidden`). No dependency changed. |
| Android build / emulator / device | **not run** (see section 6) |

During this pass the sweep caught one false alarm and one real gap in my own test tooling:
- A Profile capture appeared to show Log out under the nav. The helper had scrolled a hidden tab's
  list instead of the visible screen. The helper now scrolls with the mouse wheel over the visible
  screen, as a user would.
- The new R43 check at first matched the wrong tab list. Both were fixed, and R43 now measures the
  real gap: Log out ends 17 dp above the nav at every size.

## 6. Android or browser preview?

**Browser preview only.** All captures are react-native-web renders in headless Chromium. No
Android runtime screenshots were captured, and none of these images should be taken as Android
output. These were not verified:
- device animation smoothness;
- the hardware Back key;
- TalkBack;
- status and navigation bar insets on real hardware;
- how the PNG nav icons look at real device densities.

To enable an emulator in this environment, `dl.google.com` has to be added under **Allowed domains**
in the environment's network settings (cloud environment menu → Edit → Network access; keep
"Allow package managers" ticked; see https://code.claude.com/docs/en/cloud-environments#network-access).
The container also needs hardware virtualisation (`/dev/kvm`), which it does not have today. A local
`npx expo run:android` or an EAS build on your side is the practical route.

## 7. Remaining issues that need your input

1. **Video banners (Part 4):** the backend's Home banners are images only. Video needs a banner
   media-type field, upload and validation in Admin, and a player library such as `expo-video`,
   which is a new dependency. Say if you want this, and I'll add it with your approval of the dependency.
2. **Profile photo:** the backend has no avatar upload, so avatars are initials inside the coloured
   rings. Photo upload would need a backend endpoint and storage bucket.
3. **Transparent originals:** the badges and nav icons were recovered from checkerboard-baked PNGs.
   On these dark themes the result is clean. Sending the original transparent PNGs or SVGs would
   remove any risk of faint edge fringes.
4. **Badge labels are baked into the artwork** ("ONGOING", "UPCOMING", "COMPLETED"). They can't be
   translated or restyled per theme. Text-free versions would allow that.
5. **Game titles are one line** inside the card ("Free Fire Show…" at 390 dp). Two lines would cover
   more of the artwork. Say if you prefer that.
6. **Support row:** still omitted from Profile because there is no support feature in the backend.
7. **Linting and unit tests:** the mobile app has no ESLint or test runner configured. Adding them
   means new dev dependencies, so it needs your approval.
