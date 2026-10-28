# Icons, Profile, Admin, UI fixes and Ads & Monetization — report

Covers the four requests delivered together: icon integration / Profile / Admin polish,
the ads, rewards and monetization system, the tournament-card / Esports Games fix, and the
bottom-navigation glow fix.

**What "tested" means here.** Backend behaviour is covered by automated tests (all passing).
The app was exercised as a **web render** (Expo web export in Chromium via Playwright,
against the real local Laravel API) — **not on Android**: this environment cannot build or
run Android (section 5). No real ad has been served, no LevelPlay callback has been
received, no ad revenue exists, and nothing here claims otherwise. Rewarded flows in the
web render go through a clearly labelled **QA-only test-ad dialog** that completes claims via
the server's simulation path (`verification_method = simulated`), which is refused in
production and excluded from every Admin figure.

---

## 1. Icon integration, Profile, Admin panel and UI polish

**1. Supplied assets and where each is used** — full table in
[`mobile/assets/ICONS.md`](../mobile/assets/ICONS.md): 19 icons, 3 status artworks and 3
nav icons cut from the supplied sheets (`11.png`, `12.png`, `13.png`) and, for the five icons
that exist only there (calendar without clock, bell without dot, Solo / Duo / Squad), from
`photo_2026-10-23_21-20-56.jpg`. All real-alpha PNGs, kept at their own aspect ratio by
`<Artwork>` (`mobile/src/theme/artwork.tsx`); the contact sheets are never shown. Regenerate
with `mobile/scripts/extract-alpha-icons.py`.

**2. Mobile files changed** — screens: `app/(tabs)/index.tsx`, `(tabs)/profile.tsx`,
`(tabs)/leaderboard.tsx`, `_layout.tsx`, `deposit.tsx`, `wallet.tsx`, `withdraw.tsx`,
`settings.tsx`, `tournament/[id]/index.tsx`, `tournament/[id]/results.tsx`; new
`matches.tsx`, `ads-earning.tsx`. Components: `Button`, `RedeemCodeDialog`,
`UnlockSectionDialog`, `cards/GameCard`, `cards/MenuRow`, `home/HomeHeader`,
`home/ShortcutCards`, `leaderboard/Podium`, `leaderboard/RankRow`, `navigation/BottomNav`,
`tournament/JoinSheet`, `tournament/TournamentCard`, `tournament/TournamentListView`,
`tournament/tournamentState`; new `tournament/TicketProgress`, `tournament/useTicketedJoin`,
`visual/ProgressBar`, `visual/UnreadDot`, `theme/artwork.tsx`, `notifications/useUnreadCount`,
`api/appConfig`, `utils/supportLink`, and the `src/ads/` module. Also `theme/tokens.ts`,
`types.ts`, `api/games.ts`, `app.json`, `package.json` (+ lock), `plugins/withLevelPlay.js`,
`scripts/check-no-raw-currency.js`. Old `assets/badges/*` and the checkerboard extractor were
removed (replaced).

**3. Admin panel files changed** — `app/View/AdminNavigation.php` (Customer Support under
Communication; new "Ads & Monetization" group), `Livewire/Admin/CustomerSupportSettings`,
`PlatformSettingsEditor` (scoped to its own group), `RedeemCodes` (+ "Requires a rewarded ad"),
`TournamentCreate` (Free / Paid + tickets), the 12 pages under `Livewire/Admin/Ads/`, and their
views under `resources/views/livewire/admin/ads/`.

**4. Laravel routes, controllers, migrations, settings** — see section 2 items 2–4.
Settings live in the existing platform-settings catalog
(`app/Domain/Settings/PlatformSettingsService.php`), now grouped (`support`, `ads`,
`ads_earning`, `daily_reward`, `tickets`), validated server side (incl. shares totalling
100%, min ≤ max) and audit-logged.

**5. Profile order (as implemented, top to bottom)** — avatar card (centred), username,
member since, stats (Total Earnings · Matches · Deposits), then rows: My Profile, Wallet,
Notifications (red dot only when the server's unread count > 0), Redeem Code, Settings,
Terms and privacy, Customer Support, Ads Earning, Total Matches, and **Log out last**, red,
set apart, with a confirmation. Each row: supplied colour icon, same height, glass
background, rounded, chevron, whole row tappable.

**6. Home status cards** — the supplied ONGOING / UPCOMING / COMPLETED artwork with the
baked labels removed (the hidden lower rim of the Completed circle is completed from its
own pixels); native text "Ongoing / Upcoming / Completed" and the server's count,
right-aligned in green, on a left-to-right translucent glass strip inside the card.

**7. Leaderboard** — gold / silver / bronze supplied medals for ranks 1–3 (podium and
results), `#4` … `#100` for the rest.

**8. Wallet control** — the header balance is a rectangular rounded control (radius 12),
coin PNG + server balance; the supplied yellow bell sits beside it with the real unread dot.

**9. Deposit green, Withdrawal red** — `Button` variants `success` (#15803d, white text) and
`danger`, with the supplied wallet+down-arrow and cash+up-arrow artwork, everywhere those
actions appear (Wallet, Deposit, Withdraw, Join / Unlock top-up prompts).

**10. Customer Support URL** — Admin › Communication › Customer Support (permission
`settings.manage`): view, edit, test link. Only `https://` and `mailto:` are accepted
(server-validated; `javascript:`, `data:`, `http:`, `intent:`, `file:` and malformed values
are refused), persisted, audit-logged, served by `GET /api/v1/app-config`. The app opens it
from Profile and shows a plain message if it isn't set or can't be opened.

**11. Ads Earning toggle and integration status** — Admin › Ads & Monetization › Ads
Earning has the **Rewarded Ads** card with an Enabled / Disabled switch, current status badge,
the requested explanation, saving/error states and "last changed by". It saves through the
backend and survives reloads (verified in a real browser and by tests). Integration status:
the full server-side reward flow and the app's LevelPlay integration are implemented and
tested as described in section 2; **live ads are not yet integrated with real provider
accounts** — that needs the provider setup in `docs/ads-monetization.md` and an Android
build (section 5).

**12. Tests** — section 4.

**13. Blockers / not completed** — section 5.

---

## 2. Ads, rewards, wallet and monetization

Architecture, policy conflicts and operations are documented in
[`docs/ads-monetization.md`](../docs/ads-monetization.md). Key decisions:

* **Server-authoritative rewards.** Every ad reward goes through one claim flow: the server
  checks the rules and opens a claim; the app passes it to LevelPlay as the dynamic user ID;
  only LevelPlay's **signed server-to-server callback** completes it (MD5 signature with the
  server-only private key, provider event ID unique, claim locked, ledger idempotency key).
  No endpoint trusts a client "rewarded: true".
* **Policy conflicts → compliant alternatives.** Paying for ad views only through rewarded
  inventory, so Ads Earning shares only the Ads Earning rewarded placement's revenue;
  per-user revenue is client-reported and forgeable, so settlements use Admin-finalized
  provider revenue split by verified units (aggregate model); app-open ads are not offered
  by the RN plugin, so that placement is marked unsupported; "full-screen native" doesn't
  exist, so the post-withdrawal placement is an interstitial (paid withdrawals only, once,
  within 72 h, never a condition of payout).
* **One wallet.** All credits use the existing `WalletService` ledger (Deposit balance),
  new ledger types `daily_login_reward`, `ad_campaign_reward`, `ads_earning_reward`.

**1. Files created and modified** — the full list is the commit's file list; the main new
code: `backend/app/Domain/Ads/*` (12 services), 4 API controllers + the callback controller,
`CreditAdSettlementJob`, `ads:maintain`, 15 models, 1 migration, 12 Admin pages,
`mobile/src/ads/*`, `mobile/app/ads-earning.tsx`, ticket components, `withLevelPlay.js`.

**2. Migration and models** — `2026_10_23_161311_create_ads_monetization_tables`:
`ad_units`, `ad_placements` (fixed catalog of 12), `ad_events`, `rewarded_claims`
(unique token; unique provider+event), `tournament_ad_tickets` (unique per claim),
`daily_reward_claims` (unique player+day), `daily_reward_dismissals`, `redeem_reservations`,
`ad_reward_campaigns`, `ad_callback_logs`, `ads_earning_preferences`, `ad_revenue_reports`
(estimated / finalized / disputed), `ad_settlement_batches` (unique period),
`ad_settlement_allocations`, `withdrawal_ad_attempts` (unique per withdrawal);
`tournaments.required_ad_tickets`, `redeem_campaigns.requires_rewarded_ad`; permissions
`ads.view`, `ads.manage`.

**3. API endpoints** — new: `GET /api/ads/levelplay/callback`; `GET /api/v1/ads/config`,
`GET /api/v1/ads/campaigns`, `POST /api/v1/ads/events`, `POST /api/v1/ads/claims`,
`GET /api/v1/ads/claims/{token}`, `POST …/cancel`, `POST …/simulate` (QA only, 404 in
production), `POST /api/v1/ads/withdrawals/{id}/post-success`, `GET /api/v1/daily-reward`,
`POST /api/v1/daily-reward/dismiss`, `POST /api/v1/daily-reward/claim` (only when Admin turned
the ad requirement off), `GET /api/v1/ads-earning`, `POST /api/v1/ads-earning/preference`,
`GET /api/v1/ads-earning/history`, `GET /api/v1/app-config`. Changed: `POST /api/v1/redeem`
(202 `ad_required` for ad-gated codes), `GET /api/v1/tournaments` (`joined` filter, ticket
fields), tournament join (refused until tickets are complete), withdrawals (`settled_at`).

**4. Admin routes and pages** (`/admin/ads/*`, mirrored under `/staff/ads/*`): Overview,
Ads Earning (Rewarded Ads switch + global switches + revenue-share rules), Ad units,
Placements, Revenue reports, Weekly settlements, Daily login rewards, Reward campaigns,
Tournament tickets, User ad activity, Verification logs, Audit log. `ads.view` opens the
read-only six; `ads.manage` is required for the rest and re-checked on every action. Also:
Create tournament (Free with tickets, default 1 / Paid with fee), Redeem codes ("Requires a
rewarded ad"), Customer Support.

**5. Mobile screens and components** — Ads Earning screen (status, switch, deposit progress
with the coin, weekly verified ads / pending / credited, last settlement, Watch ad, Bonus
rewards Claim, history with pagination, help sheet); daily login popup (range, Watch Ad &
Claim, "Don't show again today", X; once per app session; success state with balance);
ticket progress on free-tournament cards and details (ticket artwork, `n/N`, bar, lock that
fades at completion; Join Now plays one ad then re-reads the count; never auto-joins);
redeem dialog's ad step; labelled banner/native slots (Home above hero, tournament feed,
leaderboard feed, end of Wallet) that stay collapsed unless an ad fills; game-card and
post-withdrawal interstitials with cooldowns and daily caps; personalised-ads consent sheet +
Settings choice; wallet ledger labels for the new reward types.

**6. SDK and adapters** — `unity-levelplay-mediation` **9.3.0** (exact, the only new
dependency; LevelPlay mediation SDK 9.6.1). Build plugin pins Unity Ads adapter **5.15.0**,
AdMob adapter **5.19.0**, Play services app set 16.0.2 / ads-identifier 18.0.1 / basement
18.3.0. *Verified*: package install, TypeScript typings, `expo prebuild` output of the plugin.
*Not verified*: Gradle resolution and runtime on a device (Maven blocked here) and the adapter
compatibility table (section 5).

**7. Provider dashboard configuration** — `docs/ads-monetization.md` §3–§5: LevelPlay app
(`com.minimax.esports`), ad units per format, AdMob and Unity Ads networks, placements named
like the app's keys, test devices, S2S callback URL `https://<domain>/api/ads/levelplay/callback`
with a private key, and the **required test-callback check** in Verification logs.

**8. Environment variables** (placeholders in `backend/.env.example` and
`.env.production.example`): `MINIMAX_ADS_ENABLED`, `MINIMAX_ADS_REWARDED_ENABLED`,
`MINIMAX_ADS_TEST_MODE`, `LEVELPLAY_APP_KEY`, `LEVELPLAY_S2S_PRIVATE_KEY` (secret, server
only), `MINIMAX_ADS_ALLOW_SIMULATED_REWARDS` (local QA only); build-time
`LEVELPLAY_ADMOB_APP_ID` / `LEVELPLAY_USE_ADMOB_TEST_APP_ID=1`. No production ID or secret is
in the code.

**9. Android build** — a new EAS build is required (native module + plugin): set the EAS
secrets above, `eas build -p android --profile preview`, install on a registered test device,
run LevelPlay's Integration Test Suite. Existing builds simply never show ads.

**10. Migrations** — `php artisan migrate --force` (creates the tables, seeds the
placement catalog and the two permissions).

**11. Scheduler / queue** — `ads:maintain` every 5 minutes (already registered in
`bootstrap/app.php`; covered by the existing single `schedule:run` cron) and a running queue
worker for `CreditAdSettlementJob` (`docs/deployment.md` updated).

**12. Automated tests** — section 4.

**13. Unresolved build/test errors** — none in what could be run here. Android build not
attempted (section 5).

**14. Needs live credentials or a real device** — any real ad fill; LevelPlay init on
Android; the S2S signature/parameter match (test callback); AdMob/Unity adapters resolving
and serving; banner/native rendering; interstitial caps on device; consent wiring to the
SDK; impression-revenue telemetry; real revenue entry and a real weekly settlement.

**15. Implemented / tested / not yet verified**

| Area | Implemented | Tested | Not yet verified |
|---|---|---|---|
| Claim flow + S2S verification, replay/duplicate protection | ✓ | Feature tests (signed callbacks, bad/stale signatures, duplicate and reused events) | Against LevelPlay's real callback |
| Tournament tickets (free only; paid unchanged; no auto-join) | ✓ | Tests + web render (0→1→2/3, early close adds nothing) | On device with real ads |
| Daily login reward (server random in range, 1/day IST, dismiss, X) | ✓ | Tests + web render (popup 3 sizes, X, claim via QA test ad) | Real ad |
| Ads Earning (eligibility from confirmed deposits, switch, units, daily cap) | ✓ | Tests + web render (switch on, verified unit counted) | Real ad |
| Weekly settlement (bcmath, 60/40 validated, idempotent credit, retry) | ✓ | Tests (incl. crash-after-credit) + Admin page end-to-end test | With real finalized revenue |
| Reward campaigns (Model B) | ✓ | Tests + web render claim | Real ad |
| Redeem code + rewarded ad (atomic reservation, expiry) | ✓ | Tests + web render | Real ad |
| Post-withdrawal interstitial (paid only, once, ≤72 h) | ✓ | Tests | On device |
| Banner / native / interstitial placements, caps, labels | ✓ (code) | Typecheck only; they render nothing without the SDK | On device |
| Admin Ads & Monetization (12 pages, permissions, audit) | ✓ | 13 page tests + real-browser sweep (53/53 checks) | — |
| Analytics (test/simulated excluded, distinct metrics) | ✓ | Tests | With production traffic |
| App-open ads | Not offered (SDK has no format) | — | — |

---

## 3. Tournament cards, Esports Games, game titles, navigation glow

* **Tournament cards** — every listing (Upcoming / Ongoing / Completed, a game's list, My
  matches) uses `TournamentCard`: a full-width 16:9 image on top (`resizeMode="cover"`,
  centred), details below in the same rounded card. No left thumbnail anywhere.
* **"Esports Games"** — the Home heading reads exactly `Esports Games`; no `eSports` remains
  in the app.
* **Game card titles** — anchored near the bottom with a small inset, up to two lines, never
  overlapping the green count.
* **Bottom navigation** — crisp purple line + halo + downward haze, clipped to the bar;
  the light travels to the new tab in ~340 ms with a glow expansion, icon pop and label
  transition; reduced motion falls back to fades; no new dependencies.
* Colours preserved: `#6D28D9`, `#17111F`, `#F5C451`, `#20D58A`, `#F04452`.

---

## 4. Tests actually run (this session, final code)

| Check | Result |
|---|---|
| Backend `php artisan test --compact` | **622 passed**, 5,291 assertions, 0 failed |
| Backend `vendor/bin/pint --dirty` | passed |
| Mobile `npx tsc --noEmit` | passed |
| Mobile `npm run check:currency` | passed (no raw ₹, no ad-hoc coin SVG) |
| Admin real-browser sweep (Chromium, 1440 and 390 wide) | **53/53** checks — [`admin-sweep-results.json`](ads-monetization/admin-sweep-results.json) |
| App web-render sweep, 360×640 / 390×844 / 412×915 | **17/17** viewport checks (popup shown at each size, X closes it, claim after verified test ad, no horizontal overflow on Home, Ads Earning, Tournaments) + **7/7** flow checks — [`app-flows-results.json`](ads-monetization/app-flows-results.json) |
| UI probe of prompt-(a) screens at the three sizes | no page errors at any size |

Two project guards caught real problems during this work and were fixed, not bypassed: the
"no raw ₹ in Blade" guard (Admin views now use coins / "INR"), and the "flash-status pages
render the flash" guard, which was **extended** to follow components in sub-folders and the
traits they use (then shown to fail when a flash is removed). The sweep also found the
locked Join Now button announcing itself as "Join" to screen readers — fixed.

Screenshots (genuine captures; **web render, not Android**):
`qa-report/ads-monetization/app/` (24), `ui-final/` (30), `admin/` (26).

---

## 5. Blockers and remaining work

* **No Android build or device run from this environment.** The network policy refuses
  `dl.google.com` (Google Maven) and LevelPlay's Maven (`android-sdk.is.com`) with a proxy
  403, and there is no `/dev/kvm` for an emulator. To build here, allow those hosts under
  the environment's Network access (Allowed domains, keeping "Allow package managers"):
  https://code.claude.com/docs/en/cloud-environments#network-access — or run the EAS build
  from your own machine.
* **Provider setup is yours to do**: LevelPlay / AdMob / Unity Ads accounts, ad units, the
  S2S private key, and the test-callback confirmation (`docs/ads-monetization.md` §4.3).
  Until then everything stays off and no reward can be verified.
* Adapter versions must be checked against LevelPlay's compatibility table before release.
