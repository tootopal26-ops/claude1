# Spin & Token Rewards System — Implementation & QA Report

Full implementation of the "Spin & Win" rewarded-ad wheel and the token-for-coins exchange,
integrated into the existing Mini Max app, API, database and ad-mediation system. No demo
screen, no fake reward flow, no hardcoded balance.

## What was built

### Backend (Laravel)

- **Migration** `2026_10_31_090000_create_spin_and_token_exchange_tables.php`: seeds the
  `spin`, `token_exchange` (rewarded) and `spin_feed` (native) ad placements; creates
  `user_token_balances`, `token_ledger_entries` (append-only, idempotent on a unique key),
  `spin_attempts` and `token_exchange_transactions`; widens `rewarded_claims.purpose` to
  include the two new purposes and adds `reward_tokens` / `exchange_tier_index` columns.
- **`TokenService`** (`app/Domain/Tokens`): the one place that ever moves a token balance —
  `credit()`/`debit()`, both idempotent on a caller-supplied key, both inside a locked DB
  transaction. Whole-number arithmetic (no bcmath needed — every token amount is an integer
  by design).
- **`SpinService`** (`app/Domain/Spin`): the 8-segment wheel layout (`1×3, 2×2, 3×1, 4×1,
  5×1`, fixed), a cryptographically secure weighted draw (`random_int` over integer basis
  points — never a float, never client-supplied), and `creditSpin()`, which is idempotent on
  the verified claim and only ever called from `RewardedClaimService::applyReward()` — i.e.
  only after the ad network's signed callback confirms the ad.
- **`TokenExchangeService`** (`app/Domain/Tokens`): the 10 fixed tiers exactly as specified
  (50→1 … 20,000→420, tiers 5-10 carrying the +5% bonus already baked into the coin figure —
  nothing is recomputed, so the app and the ledger can never drift); `assertCanOpen()` blocks
  opening an ad claim at all if the balance is insufficient; `exchange()` debits tokens and
  credits the **existing** Deposit wallet (reusing `WalletService::creditReward()`'s
  previously-unused `ad_reward` type) inside one transaction, idempotent on the claim.
- **Wired into the existing ad pipeline**, not a parallel one: `AdsConfig::PURPOSE_PLACEMENTS`
  gained `spin`/`token_exchange`; `RewardedClaimService::open()`/`applyReward()` gained a
  branch each, calling straight into `SpinService`/`TokenExchangeService` — the same
  open-claim → S2S-callback → verify → credit path every other reward already uses.
- **API**: `GET /spin`, `GET /tokens`, `GET /exchange/tiers`, `GET /exchange/history`; spin
  and exchange claims go through the existing `POST /ads/claims` (`purpose: spin` /
  `token_exchange` + `exchange_tier`).
- **Admin panel**: new "Spin & token rewards" page (`Ads & Monetization`) — the odds form
  (validated server-side to sum to exactly 100%), the fixed tier table, and read-only spin /
  exchange activity tables, mirroring the existing Daily Rewards page's structure exactly.

### Mobile (Expo/React Native)

- **Real assets, not placeholders**: the two supplied images were split into
  `assets/spin/spin-frame.png` (the stationary frame, with its own built-in pointer) and
  `assets/spin/spin-wheel.png` (the rotating wheel), and the user-supplied `assets/token.png`
  crown-coin icon replaces the earlier placeholder plan entirely.
- **`SpinWheel`** (`src/tokens/SpinWheel.tsx`): renders the frame and wheel as two independent
  `Image` layers at their own native aspect ratios, the wheel sized to
  `frameWidth × 0.6843` (the frame's measured inner-opening ratio) and centred — no stretch,
  no second pointer drawn. The server's 8 segments (never hardcoded in two places) decide
  which physical segment the winning number lands under; the wheel only ever spins forward
  (a cumulative rotation value), decelerating into that exact segment.
- **`SpinButton`**: the green "SPIN" / "Watch Ad to Spin" button (new `tv` icon added to the
  shared icon set for the subtitle), with its own gradient/glow/press/loading/disabled states.
- **`TokenBalancePill`**: `[token icon] [balance] | EXCHANGE`, a subtle pop on increase, backed
  by the new `TokenContext` (the same shape as the existing `WalletContext` — one real
  server-fetched balance, shared by every screen, refreshed by `AdsContext.watchRewarded`
  immediately after a verified `spin`/`token_exchange` claim).
- **Win popup**: `ConfettiBurst` (plain `Animated.View`s, no new SVG dependency) + "YOU WON!"
  + token icon + amount + "TOKENS" + Continue — shown only from `SpinWheel`'s
  `onSpinComplete`, i.e. after the wheel has physically stopped on the already-verified result.
- **`app/token-exchange.tsx`**: the 10 fixed tiers (token amount → coin amount, bonus chip,
  disabled "Not enough" when unaffordable), the Confirm dialog, and the success popup —
  exactly the ad-gated flow the spec describes, through the same `watchRewarded()` every
  other reward uses.
- **`AdSlot` `spin_feed`** placement added for the native ad below the wheel, above the bottom
  nav — collapses to nothing on no-fill, exactly like every other `AdSlot` in the app.
- Sound: reuses the existing engine's `playReward()`/`playCoin()`/`playWarning()` at the
  verified moments (never before), per "use existing app settings if available" — no new
  audio assets.

## Bugs found and fixed during this work

1. **Sqlite-breaking migration (whole test suite).** The enum-widening statement was raw
   MySQL `ALTER TABLE ... MODIFY`, which doesn't exist on SQLite — the test suite runs on
   SQLite, so this one migration was silently failing every single feature test in the repo
   (not just the new ones) the moment it was added. Fixed by using `Schema::table(...)->change()`,
   which Laravel compiles correctly for both drivers.
2. **`balance: null` on a brand-new player.** `UserTokenBalance::firstOrCreate(['user_id' =>
   ...])` never included `balance` in the actual INSERT, so a freshly created row's in-memory
   model had no `balance` attribute at all — `/spin` and `/tokens` would return `"balance":
   null` instead of `0` for anyone who had never spun before. Fixed by passing `['balance' =>
   0]` as the second `firstOrCreate` argument in both call sites.
3. Caught and fixed a self-inflicted test-only artifact: `Sanctum::actingAs()` makes
   `$request->user()` resolve on *every* route for the rest of that test, including the
   public LevelPlay callback route — so a test exercising many real spins) shared one
   rate-limit bucket with the ad-claims endpoint. Confirmed this cannot happen in production
   (the callback route carries no `auth:sanctum` middleware) and disabled throttling for
   that one test file rather than masking it any other way.

## Tests

- **`AdminSpinRewardsPageTest`** (4 tests): settings load, the 100%-sum cross-field
  validation, real spin/exchange activity reflected in the stats, the tier table.
- **`SpinAndTokenExchangeTest`** (8 tests): the weighted draw's statistical distribution
  (20,000 trials against 80/10/5/3/2, ±2pt tolerance), spin status, spin disabled → refused
  before any claim, a verified spin credits tokens exactly once (duplicate network callback
  included), the exchange tier table, an unaffordable exchange refused before any ad, a full
  spin-to-afford-a-tier-then-exchange journey with exact wallet/token amounts, and a
  tier-1-still-unaffordable rejection.
- `AdminAdsPagesTest`'s permission matrix extended to cover the new `/spin` admin page.
- **Full suite: 653/653 passing** (`php artisan test`), Pint clean.
- Mobile: `npx tsc --noEmit` clean, `npm run check:currency` clean (116 files, including the
  two new files added to the SVG allow-list for decorative gradients only).

## Live verification (screenshots in `qa-report/spin-token-rewards/`)

Could not run `npx expo start`/`expo lint`/`expo export` normally — every `expo` subcommand in
this sandbox hits a network policy block (`HTTP Proxy Network Error: Forbidden`) trying to
reach Expo's own servers (telemetry/update checks), independent of anything in this change.
Worked around it with `EXPO_OFFLINE=1 npx expo export -p web` (a local Metro bundle, no Expo
network calls) against a throwaway `react-dom`/`react-native-web` install (`npm install
--no-save`, never touching `package.json`/the lockfile), served statically and driven with
Playwright against the real backend (migrated, seeded, with a real test account) end to end:

1. **Spin screen, ready state** — frame + wheel composited correctly (no stretch, wheel sits
   exactly inside the frame's ring, the frame's own pointer visible, no second pointer drawn),
   token pill top-right, green SPIN button with TV icon + "Watch Ad to Spin".
2. **Test-ad dialog** (QA-only simulated ad, the same dialog every other reward in the app
   already uses — real LevelPlay credentials are not available in this environment).
3. **Wheel mid-spin** — confirms the rotation animation is live and the token balance already
   updated (to 1) *while the wheel is still turning*, proving the credit happens on backend
   verification, not on the cosmetic animation finishing.
4. **Win popup** — "YOU WON!", confetti, the token icon, the exact awarded amount, Continue.
   The wheel's resting position matches the awarded number exactly.
5. **Exchange tiers** — all 10 rows, bonus chips on tiers 5-10, "Not enough" correctly
   disabled on tiers the test account couldn't afford.
6. **Confirm exchange** dialog — tokens/coins with their icons, current balance, Cancel/Confirm.
7. **Exchange successful** popup — the exact coin amount, "credited to your Deposit Wallet",
   and the token balance underneath already debited (101 → 51 for a 50-token tier).
8/9. **Responsive** — 360×740, 428×926 and 768×1024, no horizontal scroll at any size, wheel
   alignment preserved, content column capped and centred on the tablet width.

A full regression pass (Home, Leaderboard, Profile) after these changes showed no visual or
functional regressions.

## Final checklist

| # | Requirement | Status |
|---|---|---|
| 1 | Premium purple/gold UI, no demo screen | ✅ integrated into `app/(tabs)/spin.tsx` |
| 2 | Frame + wheel as two layers, frame's own pointer only | ✅ |
| 3 | Wheel fits exactly inside frame, no distortion | ✅ (measured `0.6843` inner ratio) |
| 4 | Exactly 8 segments, 1×3/2×2/3×1/4×1/5×1 | ✅ fixed in `SpinService::SEGMENTS`, mirrored on mobile |
| 5 | Green SPIN button, TV icon subtitle, states | ✅ `SpinButton` |
| 6 | Ad required before any spin result | ✅ `watchRewarded` → verified claim only |
| 7 | No reward on open/dismiss without verification | ✅ server never credits until S2S callback |
| 8 | Exact probabilities 80/10/5/3/2, backend-decided | ✅ `random_int`, 20k-trial test |
| 9 | Frontend animates to server result, never invents one | ✅ `targetNumber` prop, cosmetic segment choice only |
| 10 | Winning popup, confetti, token icon, Continue | ✅ |
| 11 | Token balance pill, EXCHANGE link, real balance | ✅ `TokenBalancePill` + `TokenContext` |
| 12 | Exchange page, 10 fixed tiers, no fractional coins | ✅ |
| 13 | Confirm → ad → verified → debit+credit | ✅ |
| 14 | Coins land in existing Deposit wallet | ✅ `WalletService::creditReward` |
| 15 | Transaction history, idempotent, atomic | ✅ ledger tables + idempotency keys |
| 16 | Native ad below content, above bottom nav | ✅ `AdSlot placement="spin_feed"` |
| 17 | Animations, sounds only after confirmation | ✅ |
| 18 | Backend never trusts client numbers | ✅ |
| 19 | Admin panel integration | ✅ odds + stats + activity page |
| 20 | Responsive, no clipping | ✅ verified 3 sizes |
| 21 | Existing app functionality intact | ✅ full suite green + manual regression pass |

## What still needs real credentials/config before production

- **LevelPlay ad SDK**: this sandbox has no real ad network credentials, so every "watch ad"
  action above went through the app's existing QA-only simulated-ad dialog (same path every
  other reward in the app uses when there's no live SDK) — not a gap introduced by this
  feature. Real spin/token_exchange/spin_feed ad unit IDs need to be entered in Admin → Ads &
  Monetization → Ad Units, and the two new placements switched on in Placements.
- **Spin odds are off by default** (`spin_enabled=false`) until an admin turns it on in
  Admin → Ads & Monetization → Spin & token rewards, same as every other reward toggle.
- Both the wheel/frame artwork and the token icon are **real, user-supplied assets** — not
  placeholders — already in `mobile/assets/`.
