# Ads, rewards and Ads Earning — setup and operations

How Mini Max's ads work, what has to be configured outside this repository before any
real ad or reward runs, and how the weekly Ads Earning settlement is operated.

**Status when this was written:** the code, Admin pages and tests are in place. No real
ad has been served, no LevelPlay callback has been received, and no ad revenue exists —
the LevelPlay, AdMob and Unity Ads accounts and keys still have to be created and
entered (sections 3–5), and the reward callback has to be confirmed with LevelPlay's
test callback (section 4.3). Everything ships switched **off**.

---

## 1. How it fits together

| Piece | Where | What it does |
|---|---|---|
| Mediation SDK | `unity-levelplay-mediation` 9.3.0 (mobile) | Loads rewarded, interstitial, banner and native ads; LevelPlay picks between AdMob and Unity Ads. |
| Build plugin | `mobile/plugins/withLevelPlay.js` | Adds the Unity Ads and AdMob adapters and the AdMob App ID at EAS build time. |
| App wrapper | `mobile/src/ads/` | `sdk.native.ts` (LevelPlay), `sdk.ts` (no-SDK fallback: web, Expo Go), `AdsContext.tsx` (consent, config, the one rewarded flow, interstitial caps), `AdSlot.tsx` (labelled banner/native slots), `DailyRewardPopup.tsx`. |
| Configuration | Admin > Ads & Monetization | Global switches, ad units, placements, revenue, settlements, daily reward, campaigns, tickets. Served to the app by `GET /api/v1/ads/config` (public values only). |
| Rewarded claims | `app/Domain/Ads/RewardedClaimService.php` | The only way any ad reward is granted (below). |
| Reward callback | `GET /api/ads/levelplay/callback` | LevelPlay's signed server-to-server (S2S) callback. The **only** thing that completes a claim. |
| Settlement | `app/Domain/Ads/AdSettlementService.php`, `CreditAdSettlementJob` | The weekly Ads Earning revenue share. |
| Telemetry | `POST /api/v1/ads/events` → `ad_events` | Requests, loads, no-fills, impressions, errors and the SDK's revenue *estimate*. Analytics only — never pays anyone. |

### 1.1 The rewarded flow (tickets, daily reward, Ads Earning, redeem codes, campaigns)

1. The app asks the server to open a claim for one purpose (`POST /api/v1/ads/claims`).
   The server checks every rule for that purpose first (tournament open and not full,
   deposits at the minimum, not already claimed today, campaign budget…) and returns a
   claim token.
2. The app passes `"mm" + token` to `LevelPlay.setDynamicUserId()` and shows one rewarded ad.
3. When the player finishes the ad, LevelPlay calls the S2S callback with that user ID,
   a unique event ID and a signature. The server verifies the signature, locks the claim,
   records the provider event ID under a unique index (a replayed or duplicated callback
   can't apply twice) and applies the reward once, through the existing wallet ledger
   with an idempotency key.
4. The app polls the claim (`GET /api/v1/ads/claims/{token}`) and shows the result only
   once the server says `verified`. Closing the ad early, a load failure or a no-fill
   grants nothing. Nothing the app sends — including LevelPlay's client-side
   `onAdRewarded` — can complete a claim.

### 1.2 Reward models (one ad never earns twice)

| Placement | Reward | Model |
|---|---|---|
| `tournament_ticket` | One ticket toward a free tournament (`tournament_ad_tickets`, unique per claim). Earning every ticket only unlocks Join. | Fixed (non-coin) |
| `daily_login` | Today's daily reward, drawn on the server within Admin's range, unique per player per day (Asia/Kolkata). | Fixed |
| `redeem_verification` | The redeem code's coins, after an atomic reservation of the code. | Fixed |
| `rewarded_general` | A reward campaign's stated coins (daily limit, optional budget). | **Model B** |
| `ads_earning` | One *unit* toward the weekly revenue share. No coins per ad. | **Model A** |

Ad rewards and the daily reward are credited to the **Deposit** balance (never the
withdrawable Winnings balance), as ledger types `daily_login_reward`,
`ad_campaign_reward` and `ads_earning_reward`. Redeem codes keep their existing deposit
credit.

### 1.3 Conflicts with provider capabilities or policy, and what was built instead

* **Paying players for viewing ads** is allowed by Google's policies only through
  rewarded inventory. So Ads Earning shares only the **Ads Earning rewarded placement's**
  revenue. Banner, native and interstitial impressions never earn anyone anything.
* **Per-user revenue isn't trustworthy.** LevelPlay's impression-level revenue reaches
  the server only as the app's own report, which a modified client can forge. Ads Earning
  therefore uses a transparent aggregate model: the provider-reported, Admin-finalized
  revenue of the Ads Earning placement for the week is split by verified units.
* **App-open ads:** the LevelPlay React Native plugin 9.3.0 has no app-open format. The
  `app_open` placement exists, marked *not supported*, and can't be switched on. An
  interstitial at launch was deliberately not used as a substitute.
* **"Full-screen native" after a withdrawal:** no such format exists in the SDK. The
  closest supported full-screen format, an **interstitial**, is used — at most once per
  withdrawal, only after the server has marked it **paid**, only within 72 hours of the
  payout, and never as a condition of the payout.

---

## 2. Environment variables

Server (`backend/.env` on the server — never in git):

| Variable | Default | Notes |
|---|---|---|
| `MINIMAX_ADS_ENABLED` | `false` | Initial value of "All ads"; Admin overrides it. |
| `MINIMAX_ADS_REWARDED_ENABLED` | `false` | Initial value of "Rewarded Ads"; Admin overrides it. |
| `MINIMAX_ADS_TEST_MODE` | `true` | While on, every ad event is recorded as test-only and excluded from revenue and analytics. |
| `LEVELPLAY_APP_KEY` | — | Public LevelPlay app key (Admin can also set it). |
| `LEVELPLAY_S2S_PRIVATE_KEY` | — | **Secret.** The private key set on LevelPlay's S2S callback. Without it every callback is refused. |
| `MINIMAX_ADS_ALLOW_SIMULATED_REWARDS` | `false` | Local QA only (section 7). Ignored when `APP_ENV=production`. |

Mobile build (EAS secrets or the build shell — never in git):

| Variable | Notes |
|---|---|
| `LEVELPLAY_ADMOB_APP_ID` | The AdMob App ID (`ca-app-pub-…~…`). Without it the AdMob adapter is left out of the build (Google's SDK would stop the app at launch otherwise). |
| `LEVELPLAY_USE_ADMOB_TEST_APP_ID=1` | Development builds only: uses Google's public sample App ID (test ads only). |

No ad unit ID, app key or secret is hard-coded in the app: ad unit IDs come from the
server's `/ads/config`, and only for placements Admin switched on.

---

## 3. LevelPlay, AdMob and Unity Ads accounts

1. **LevelPlay** (Unity Ads monetization dashboard): create the Android app with package
   `com.minimax.esports`. Note the **app key**.
2. **Ad units** in LevelPlay — one per format you will use: rewarded, interstitial,
   banner, native. Note each **ad unit ID**.
3. **AdMob**: create the app and its ad units; link AdMob as a network in LevelPlay and
   map the AdMob ad units to the LevelPlay ad units. Note the **AdMob App ID**.
4. **Unity Ads**: enable it as a network in LevelPlay and map its placements.
5. **Placements** (optional, for per-placement capping and reporting in LevelPlay):
   create LevelPlay placements named exactly like Mini Max's placement keys —
   `tournament_ticket`, `daily_login`, `ads_earning`, `redeem_verification`,
   `rewarded_general`, `home_game_card`, `withdrawal_success`, `home_hero`,
   `tournament_feed`, `leaderboard_feed`, `wallet_profile`. The app passes these names
   when showing an ad.
6. **Test devices**: add your test phones in LevelPlay (and AdMob) so they receive test
   ads. Never click your own live ads.

### 3.1 Adapter versions

`withLevelPlay.js` pins the Unity Ads adapter 5.15.0 and AdMob adapter 5.19.0 for the
mediation SDK 9.6.1 that plugin 9.3.0 uses. Before every release build, check them
against LevelPlay's **adapter compatibility table** for that SDK version, and run
LevelPlay's **Integration Test Suite** (`LevelPlay.launchTestSuite()`) on a debug build.
They have not been verified on a device from this repository: the cloud environment it
was built in could not reach Google's Maven repository or run an Android emulator.

---

## 4. Server-to-server reward callback

### 4.1 Configure it in LevelPlay

For the rewarded ad units, enable **server-to-server callbacks** and set:

* **Callback URL:** `https://<your-domain>/api/ads/levelplay/callback`
* **Private key:** a long random string; put the same value in `LEVELPLAY_S2S_PRIVATE_KEY`.

The endpoint is a `GET`, rate-limited, and acknowledges a valid callback with
`{eventId}:OK` (also for a duplicate delivery, so LevelPlay stops retrying). Anything
unsigned, forged or older than 48 hours is refused with HTTP 403 and logged.

### 4.2 What the server checks

Query parameters `appUserId`, `rewards`, `eventId`, `timestamp`, `signature`;
`signature` must equal `md5(timestamp . eventId . appUserId . rewards . privateKey)`.
`appUserId` must be `"mm"` + a 32-hex claim token opened by the server.

### 4.3 Confirm it before going live (required)

This parameter set and signature order follow ironSource/LevelPlay's documented S2S
format, but the documentation could not be reached while this was built. Before
enabling production rewards:

1. Set the URL and key in the dashboard and press **Send test callback**.
2. Open Admin > Ads & Monetization > **Verification logs**. A matching implementation
   logs the test with *signature valid* (outcome `unknown_claim`, because a test
   callback carries no real claim). A mismatch shows `bad_signature` or
   `missing_parameters` — compare the parameter names in the logged payload with
   `LevelPlayCallbackVerifier` and adjust it. It fails closed: a mismatch is refused,
   never accepted.

---

## 5. Going live, step by step (Admin > Ads & Monetization)

1. **Ad units:** add each LevelPlay ad unit ID with its format and environment.
2. **Placements:** switch on only what you want, each with an ad unit of its format.
   Feed placements take "after every 3/5/8/10" rows; interstitials take a daily cap and a
   cooldown.
3. **Ads Earning:** set the LevelPlay app key, keep **Test mode** on, switch **All ads**
   on, then **Rewarded Ads** on. Test on a registered test device: each reward type
   should appear in *Verification logs* as `verified`.
4. Switch **Test mode** off only for the production release. While it is on, nothing is
   counted as revenue.
5. Optional: **Daily login rewards**, **Reward campaigns**, **Tournament tickets**
   (default tickets for a new free tournament: 1).

Turning any switch off only stops new opportunities. Earned rewards, tickets, history
and settlements are never touched.

---

## 6. Weekly Ads Earning settlement

The week runs from Admin's settlement day and time (default Monday 06:00,
Asia/Kolkata). `ads:maintain` (every 5 minutes) opens each finished week as a
**pending** batch. Then, in Admin > **Weekly settlements**:

1. In **Revenue reports**, enter the LevelPlay dashboard's revenue for the **Ads Earning**
   placement for those days (USD), with its source. It starts *estimated*; mark it
   **finalized** when the provider's figure is final. Two finalized reports can't cover
   the same days; a wrong one is marked *disputed* (excluded) and re-entered.
2. **Calculate** the batch with the USD→INR rate and where it comes from. Recorded on
   the batch: shares, rate and source and time, coin conversion and policy version.
   * business = revenue × business % (default 60%); players' pool = revenue − business
   * pool in INR = pool × rate; pool in coins = INR × coins per INR 1
   * a player's coins = pool in coins × their units ÷ all units, truncated to 0.01
   * fixed-point decimal throughout (bcmath); the rounding remainder and allocations
     below the minimum payout are recorded on the batch, not paid.
3. Review the allocations, then **Credit settlement**. A queued, unique job credits each
   allocation with the idempotency key `ads_earning:{batch}:{player}`; a failed credit
   is kept for **Retry**, and running it again never pays twice. A completed batch is
   read-only; corrections are separate, audited adjustments.

A **unit** is one rewarded ad on the Ads Earning placement, verified by the S2S callback,
by a player who had Ads Earning switched on, had successful deposits of at least the
minimum (default 50, from provider-confirmed deposit orders only) and was within the
daily limit — decided once, when the callback is verified. Switching on later never adds
past ads; switching off never removes them.

---

## 7. Local QA without an ad SDK

The web preview and Expo Go have no LevelPlay native module, so the app falls back to
"no ad available". For local testing only, set `MINIMAX_ADS_ALLOW_SIMULATED_REWARDS=true`
on a **non-production** server: the app then shows a clearly labelled "Test ad — QA only"
dialog instead of an ad, and "Finish test ad" completes the claim through the same
server path as a callback (`verification_method = simulated`). Simulated completions are
excluded from every Admin figure and counted separately. The endpoint returns 404 in
production whatever the variable says.

---

## 8. Permissions

* `ads.view` — read-only: Overview, Revenue reports, Weekly settlements, User ad
  activity, Verification logs, Audit log.
* `ads.manage` — everything that changes something: switches, revenue share, conversion,
  ad units, placements, revenue entry, settlement calculate/credit/retry, daily reward,
  campaigns, tickets. Re-checked on every action, not only on the page.

Every change is audit-logged with the acting staff member, time, previous and new
value (Admin > Ads & Monetization > Audit log).

---

## 9. Deploying this change

```
cd backend
php artisan migrate --force          # ads tables, placement catalog, ads.view / ads.manage
php artisan schedule:list            # ads:maintain must be listed
```

A queue worker must be running (docs/deployment.md §4) for settlements to credit.
Then build a new Android app with EAS (the LevelPlay native module and the build plugin
are new) — an older build simply never shows ads.
