# Mini Max — System-wide Sound Design & Haptic Feedback Engine

**Scope:** the Mini Max **mobile app** (`mobile/`, Expo / React Native).
**Branch:** `claude/lucid-volta-ispwb4`

This report is the deliverable list requested by the spec: every created asset and its
licence/source, the central sound manager and its integration points, the settings UI, the
interactions that received sound and haptics, the build/test results, and — stated plainly —
the behaviours that could not be verified in this environment.

The Admin and Staff panels are **separate Laravel web panels**, not part of the mobile app
experience, so they are out of scope here per the spec's "if these panels are part of the same
application experience" qualifier. No sound was added to them.

---

## 1. Sound assets — all original, no placeholders

Twelve sounds were **synthesised from scratch** (not sampled, recorded, renamed, or downloaded)
by `mobile/scripts/generate-sounds.py` — pure additive synthesis (sine / triangle partials shaped
by short envelopes) rendered to 48 kHz mono AAC at 96 kbps with 2 ms edge fades and peak-limited
below 0 dBFS. They are **real, audible audio files**, verified by decoding and measuring each one
(peak/RMS levels listed below), not empty stubs.

| File | API | Length | Measured peak | Character |
|---|---|---:|---:|---|
| `click.m4a` | `playClick` | 60 ms | −16 dB | Softest in set — tiny filtered-noise tick + 2.2 kHz blip |
| `selection.m4a` | `playSelection` | 90 ms | ~−13 dB | Glassy two-partial bell blip |
| `transition.m4a` | `playTransition` | 140 ms | ~−12 dB | Soft upward pitch-glide pop |
| `close.m4a` | `playClose` | 120 ms | ~−15 dB | Quieter downward reverse pop |
| `toggle.m4a` | `playToggle` | 70 ms | ~−14 dB | Two-stage tactile tick |
| `success.m4a` | `playSuccess` | 340 ms | −5 dB | Warm rising two-note chime (G5→C6) |
| `error.m4a` | `playError` | 200 ms | ~−11 dB | Soft descending two-note (A4→F4), never harsh |
| `warning.m4a` | `playWarning` | 170 ms | ~−11 dB | Neutral mid tone with a gentle wobble |
| `reward.m4a` | `playReward` | 460 ms | −7 dB | Bright rising triad arpeggio (C6-E6-G6) with shimmer |
| `coin.m4a` | `playCoin` | 220 ms | −9 dB | Two quick high metallic pings |
| `unlock.m4a` | `playUnlock` | 300 ms | ~−9 dB | Low "thunk" then a rising confirm ping |
| `notification.m4a` | `playNotification` | 250 ms | ~−10 dB | Gentle two-note soft bell (A5→D6) |

**Total bundled size: ~41 KB across all 12 files** — negligible APK impact. No clipping on any
file. Everyday UI sounds sit in the 30–180 ms band; reward/unlock stay under 500 ms, as required.

### Licence / source

- **Source:** 100% self-authored by the generator script in this repo. No third-party assets.
- **Licence:** released under **CC0 1.0 (public-domain-equivalent)** — bundled and shipped with
  no attribution obligation and no external licence to honour.
- Manifest committed alongside the audio at `mobile/assets/sounds/SOUNDS.md`; the generator is
  deterministic (fixed RNG seed) so the files regenerate byte-stable with
  `python3 -I mobile/scripts/generate-sounds.py mobile/assets/sounds` (needs `numpy` + `ffmpeg`).

---

## 2. Central sound manager

One shared engine, one provider, one public API — nothing in the app plays audio or fires a
haptic directly.

| File | Role |
|---|---|
| `mobile/src/sound/sounds.ts` | The sound catalogue: `SoundName` union, the `require()` asset map, per-sound debounce windows, `ALL_SOUNDS`. Single source of truth for what sounds exist. |
| `mobile/src/sound/engine.native.ts` | **Device** engine (Android/iOS). Plays through `expo-audio` players; haptics via `expo-haptics`. Sets a **mix-with-others** audio session so it never ducks or interrupts other media. |
| `mobile/src/sound/engine.ts` | **Web / test** engine. Plays through the browser `Audio` element; haptics are a no-op. Metro auto-selects `.native.ts` on device and this file everywhere else — the provider never branches on platform. |
| `mobile/src/sound/SoundContext.tsx` | `SoundProvider` + `useSound` / `useSoundOptional`. Loads prefs, preloads sounds, enforces the ON/OFF + volume + haptics gates, debounces, and exposes the semantic API. |

**Public API (semantic, sound + haptic coupled):**
`playClick`, `playSelection`, `playTransition`, `playClose`, `playToggle`, `playSuccess`,
`playError`, `playWarning`, `playReward`, `playCoin`, `playUnlock`, `playNotification`, plus
`preview()` and the settings setters.

**Engineering properties (all implemented):**
- **Preloading** — every sound is loaded once on provider mount; low-latency replay via seek-to-0.
- **Graceful missing-asset handling** — a missing/again-unloaded asset is a silent no-op; the
  engine never throws into the UI.
- **Overlap / rapid-fire protection** — per-sound debounce windows (`SOUND_DEBOUNCE_MS`) so a
  double-tap or re-render storm can't machine-gun a sound.
- **Safe fallback** — on web/unsupported platforms the engine reports `soundAvailable` /
  `hapticsAvailable` and no-ops cleanly; `useSoundOptional` returns a no-op object outside a
  provider so leaf components never crash.
- **Consistent volume** — one absolute volume (0.4 / 0.7 / 1.0) applied to every sound.
- **Does not interfere with ads / other media** — the device engine uses
  `interruptionMode: 'mixWithOthers'`, `shouldPlayInBackground: false`, and plays in the normal
  (non-silent-override) session, so it mixes under music and **never** grabs the audio session
  from a rewarded ad or a video.

**Wiring:** `SoundProvider` is mounted once in `mobile/app/_layout.tsx`, just inside
`ThemeProvider`, wrapping the whole app (auth, ads, router), so every screen has access.

---

## 3. Settings UI — `mobile/app/settings.tsx`

A new **Sound & Haptics** section at the top of Settings:

- **UI sound effects** — on/off switch. When off, **no UI sound plays anywhere** in the app
  (enforced in the provider, not per-call — proven by QA below).
- **Volume** — Low / Medium / High segmented control (0.4 / 0.7 / 1.0); dimmed and inert while
  sound is off. (A continuous slider isn't used because RN core ships no slider primitive; the
  three-step control is the "where supported" fallback the spec allows.)
- **Haptic feedback** — separate on/off switch, independent of sound. Shows "This device does not
  support haptics." and disables itself when the platform reports no haptics (e.g. web).
- **Preview sound** — plays a representative sample (`selection` then `success`); itself silent-on-press so it doesn't double up.
- A note clarifying that sounds **mix** with other audio and follow the phone's silent switch and
  never interrupt a call, video, or ad.

All three preferences persist (SecureStore), load on launch, and **take effect immediately** —
flipping sound off silences the very next tap; flipping it on plays a confirming blip.

---

## 4. Interactions that received feedback

Sounds live **only in event handlers and confirmed outcomes** — never in render paths — so a
re-render or a scroll makes no sound (verified).

**Generic (every screen inherits these):**
- `Button` → soft `click` + light haptic on press (overridable / silenceable per button).
- `PressableScale` → optional `sound`/`haptic` props, fired only on a completed tap.
- Bottom nav tab → `selection` (only when changing tab).
- Sheets/modals → `transition` on open, `close` on close — fired on the actual visibility change, not re-render.
- `SegmentedControl` → `selection` when the value actually changes.
- `Switch` (new control) → `toggle` tick + light haptic.
- Tournament / game / menu cards → `selection`.

**Event-accurate, backend-gated (the money-sensitive ones):**
- **Deposit** (`app/deposit.tsx`) → `notification` when the request is *created*; `reward` + `coin` **only after the backend confirms `status === 'success'`**; `error` on failure. A success sound never implies a transaction the server hasn't confirmed.
- **Withdraw** (`app/withdraw.tsx`) → restrained `notification` on *submit* (not "paid"); `error` on failure.
- **Redeem code** (`RedeemCodeDialog.tsx`) → `success`+`coin` on confirmed redemption; `warning` for already-redeemed; `error`/`warning` on API errors; ad-verified credit → `reward`+`coin`.
- **Daily reward / ads-earning** (`DailyRewardPopup.tsx`, `app/ads-earning.tsx`) → `reward`+`coin` only on a counted/credited claim; `warning` when not counted; `error` on failure. Duplicate callbacks are debounced, so a re-fired callback can't double-chime.
- **Ticketed join** (`useTicketedJoin.ts`) → `unlock` on a verified ticket; `warning` otherwise.
- **Join flow** (`JoinSheet.tsx`) → `success` on done, `error` on catch.
- **Room credentials** (`RoomCredentials.tsx`) → `success` on copy.
- **Pull-to-refresh** (`app/(tabs)/index.tsx`) → subtle `selection` on completion.

---

## 5. Build & test results

- `npx tsc --noEmit` — **clean** (0 errors).
- `npm run check:currency` — **clean** (104 files scanned).
- Backend mobile-scanning guards (`AdminUiDesignSystemTest`, `NoRawCurrencyOrAdHocCoinSvgTest`, et al.) — **15 passed**.
- Audio-level verification of every generated file — **no clipping**, levels as tabulated in §1.

### QA — automated, in a real rendered build (`qa-report/sound-haptics/results.log`)

The web build (react-native-web via `expo export`, served and driven in headless Chromium) was
exercised end-to-end; the engine dispatches a `mm-sound` event per playback so the harness can
assert exactly which sounds fire. **10 passed, 0 failed:**

1. Bottom-nav tab → `selection` ✓
2. Scrolling / re-render → **no sound** ✓
3. Open sheet → `transition` ✓
4. Close sheet → `close` ✓
5. Preview → `selection` + `success` ✓
6. **Sound OFF silences everything** ✓
7. OFF persists across reload (still silent) ✓
8. ON again → sound returns ✓
9. ON persists across reload ✓
10. Volume control present ✓

Screenshots of the settings section in both states are in `qa-report/sound-haptics/`
(`settings-sound.webp`, `settings-sound-on.webp`).

---

## 6. Remaining unverified behaviours (environment limitation — stated honestly)

**No Android/iOS device or emulator was available in this environment, and the Android SDK / EAS
build path is blocked by the network policy.** Therefore:

- Real **audio playback and haptics were verified only via react-native-web in Chromium**, not on
  physical hardware. The web engine and the device engine share the same provider, API, gating,
  debounce, and asset set, so the *logic* is exercised — but the **device code paths
  (`expo-audio` playback, `expo-haptics` vibration, the mix-with-others audio session, and actual
  phone vibration) are NOT verified on real hardware.**
- I have **not** claimed, and do not claim, that these sounds or haptics were tested on a real
  device. That check remains to be done on an Android build (`eas build` / a dev build on a
  handset) once a device or emulator is available.
- Everything else — asset reality/levels, no-clipping, the full settings behaviour, OFF-silences-
  everything, persistence, no-sound-on-re-render, event wiring, and the type/currency/backend
  gates — **is** verified as described above.
