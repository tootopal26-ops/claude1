# Mini Max app — redesign audit, route map and plan

Written before the redesign, from the code in `mobile/` and `backend/` as they stood
(commit `8a27de6`). It records what exists, what the new specification needs that
does not exist yet, and how each gap is closed — against the real backend, never with
invented data.

## 1. Stack

| Item | Found |
|---|---|
| Expo / React Native | Expo SDK 58 (`expo ~58.0.16`), React Native 0.88, React 19.3, TypeScript 6 |
| Navigation | expo-router 58 (file routes; it bundles its own React Navigation: native-stack, bottom-tabs, `useScrollToTop`, `useIsFocused`) — one root `Stack`, no tabs yet |
| Animation | React Native `Animated` only. `react-native-reanimated` / `gesture-handler` are present in `node_modules` as transitive packages but **not** declared dependencies, so the app does not use them |
| Rendering extras | `react-native-svg` (icons, glow), `react-native-safe-area-context`, `expo-splash-screen`, `expo-linking`, `expo-web-browser`, `expo-secure-store`, `expo-notifications` |
| Not installed | blur, image cache, video, clipboard, image picker |
| Fonts | system fonts only (7 font themes map to platform families) |
| Assets | `assets/logo.png` (1200×1000 wordmark), `assets/coin.png` (the coin), app icon/splash set |

## 2. Route map (before)

| Route | Screen | Data |
|---|---|---|
| `/login` | sign in + register (one screen, mode switch) | `POST /auth/login`, `/auth/register` |
| `/` | home: header icons, wallet card, section cards, games grid, tournament list | `/wallet`, `/sections`, `/games`, `/tournaments`, `/notifications` (unread) |
| `/game/[id]` | game description + its tournaments | `/games`, `/tournaments?game_id=` |
| `/tournament/[id]` | details, join form (per-player IGN/UID), credentials, my result | `/tournaments/{id}`, `/join`, `/credentials` |
| `/leaderboard` | banner carousel, Daily/Weekly/Monthly, top 50 list, locked state | `/leaderboards/banners`, `/leaderboards/{type}` |
| `/wallet` | balances + ledger | `/wallet`, `/wallet/ledger` |
| `/deposit`, `/withdraw` | forms + history | `/deposits`, `/withdrawals` |
| `/notifications` | list, mark read | `/notifications` |
| `/profile` | header, Redeem Code card, account links, logout | — |
| `/settings` | colour + font themes | local |
| `/legal` | current terms | `/legal/current` |
| (gate) | terms (re)acceptance replaces the app | `/legal/acceptance-status`, `/legal/accept` |
| (dialogs) | redeem code, section unlock | `/redeem`, `/sections/{key}/unlock` |

Admin-managed content already consumed: game cards (Module 1), leaderboard banners and
prize rules (Module 2), redeem campaigns (Module 3–5), section prices (Module 6).

## 3. Defects found while capturing every screen (fixed in the redesign)

1. **Login form pushed off the first screen.** `BrandLogo` set only a width; a `require()`d
   image keeps its intrinsic 1000-px height, which beats `aspectRatio`. The form sat below the fold.
2. **Tournament cards and profile rows lost their styles.** `Link asChild` around a
   `Pressable` whose `style` is a function: the slot merge spreads the function into `{}`.
   Tournament cards rendered as bare text; profile rows stacked icon / label / chevron vertically.
3. **Wallet screen never showed its error.** A failed first load left `wallet` null, and the
   screen only rendered the spinner — forever, with no retry.
4. **History rows clipped their status chip** when a withdrawal carried a long failure reason.
5. **Wallet tiles overflowed** with 8-digit balances.
6. **Home hid the balance while tournaments loaded** (the whole list, header included, was
   replaced by one spinner).

## 4. What the specification needs vs. what exists

| Need | Exists? | Plan |
|---|---|---|
| 3-item bottom nav (Leaderboard / Home / Profile) | no | expo-router `Tabs` with one custom tab bar; everything else stays in the root stack |
| Home hero carousel from Admin, with links | leaderboard banners only | extend the existing banner system with a **placement** (`leaderboard` / `home`) and an optional validated **link**; Admin manages Home banners with the same pages |
| Video banners | no backend support | **not built** — documented dependency |
| Ongoing / Upcoming / Completed shortcuts with counts | list API has no status filter or counts | add `status` filter and per-status `meta.counts` to `GET /tournaments` |
| Upcoming count per game | no | `upcoming_tournaments_count` on `GET /games` |
| Tournament list per game with tabs | partial (game screen lists all) | one shared list screen for `/tournaments` and `/game/[id]` |
| Map name, booyah reward | **no such fields** | not shown (spec: omit unsupported fields) — dependency |
| Tournament image | column exists, never set by Admin | card uses the game's artwork |
| Rules | `rules` field | rules sheet |
| Join with confirmation, per-mode players | join API exists, no confirmation step | form → review (fee) → submit |
| Room credentials with copy | credentials API exists | add `expo-clipboard` (the only new dependency; Expo SDK 58 version) |
| Completed results screen | only `my_result` | add `GET /tournaments/{id}/results` (published, current results only) |
| Leaderboard podium + ranks 4–100 with earnings and prizes | top 50, no user id, no prizes | top 100, `user_id`, per-rank `prize` from the period's frozen snapshot (finalized) or current rule (live) |
| Profile picture + rings | **no avatar storage or upload** | shared `Avatar` with initials fallback; upload documented as dependency (needs image picker + endpoint) |
| Profile stats (earnings / matches / deposits) | no endpoint | add `GET /profile/stats` from results and successful deposit orders |
| My Profile | `/auth/me`, sessions API (no screen) | account screen with real details and signed-in devices |
| Support | **no support feature** | row omitted; documented |
| Notification deep links | `deep_link: tournament/{id}` in payloads | tap opens the tournament |

## 5. Plan (order of work)

1. Backend contracts above, with tests.
2. Shared design system: spacing / radius / type / motion tokens, `PressableScale`, buttons,
   `Avatar` + rank rings (no shadows), `HeroCarousel`, `SegmentedControl`, `Sheet`,
   loading / empty / error states, `ScreenHeader`, `BottomNav`.
3. Navigation: `(tabs)` group with Leaderboard · Home · Profile; branded boot screen; stack
   push transitions.
4. Home in the exact order: header (avatar, Welcome Back, username, wallet pill, bell),
   16:9 hero, three shortcut cards, 2-column 16:9 game grid with upcoming counts.
5. Leaderboard: hero, tabs, podium (2 · 1 · 3), ranks 4–100.
6. Profile, account, wallet, notifications, redeem.
7. Tournament list, cards, rules, join, credentials, results.
8. QA: every screen and state captured from the real app code (react-native-web in
   Chromium — not Android), defects fixed and recaptured; tests and type checks.
