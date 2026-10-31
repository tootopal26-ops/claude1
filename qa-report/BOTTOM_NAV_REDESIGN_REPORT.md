# WhatsApp-style Bottom Navigation Bar Redesign

**Scope:** the mobile app's bottom tab bar only (`mobile/src/components/navigation/BottomNav.tsx` and its icon set), plus the one new destination the spec itself asks for (Spin). Nothing else in the app was redesigned.

## 1. Navigation items

Four equally-spaced tabs, in order: **Leaderboard** (trophy) → **Spin** (custom 3-slot wheel) → **Home** → **Profile**. Each has its own real route (`app/(tabs)/leaderboard.tsx`, the new `app/(tabs)/spin.tsx`, `index.tsx`, `profile.tsx`) wired through `app/(tabs)/_layout.tsx`.

## 2. Icon states

New file `mobile/src/components/navigation/NavIcons.tsx` draws each icon as **two separate shapes**, not one shape with a fill toggle:

- **Unselected:** pure white (`#ffffff`) 1.9px stroke outline, `fill="none"` throughout — a truly hollow interior, no tint, consistent stroke weight across all four.
- **Selected:** a solid white silhouette (the trophy's handles stay a thin stroke so they read as handles rather than two blobs; everything else is a filled shape). No category colour, no purple, no accent anywhere in the glyphs.

**The Spin wheel** is custom-drawn, not a stock icon: a ring with three slots rotated 20°/140°/260° around the centre (120° apart, biased so the trio tilts to the upper right, per the brief). The outline draws the three slots as stroked, hollow capsules. The filled state is one solid disc with the same three slots cut out as true negative space, via an SVG `fillRule="evenodd"` path (no masks/filters/blur — the project's existing SVG rule for Android reliability) — the exact corner coordinates were computed with a small rotation script rather than hand-typed, to avoid a bad digit silently breaking the shape.

## 3. Selection animation

- Icon: scales 1.0 → 1.15 → settles back to 1.0 (`Animated.timing` out, then `Animated.spring` with the app's existing no-overshoot spring token) only on the tap that just selected it — never on mount, never under reduced motion.
- Outline ↔ filled: the two layers are stacked and cross-fade via opacity over 300 ms (within the requested 250–350 ms window), so the previous tab's icon reverses the same way instead of popping.
- Pill: a flat `rgba(255,255,255,0.14)` rounded rect slides to the newly selected item with the same spring (no glow/haze — those SVG gradient layers from the old design were removed entirely).
- Label: crosses from a dim, regular-weight white to a brighter, bold white.
- No rotation animation anywhere, no flashing, no double pop.
- Haptic: reuses the existing sound/haptics engine's `playSelection()` (a light selection haptic, already gated by the user's Settings → Haptic feedback toggle and by platform support) — it does not fire on re-tapping the already-selected tab.

## 4. Container

Flat, full-width bar (no floating side margins, no glass, no gradient, no glow, no border, no shadow), background is the theme's `sidebar` token (the app's own "navigation chrome" colour — close to the spec's `#17111F` reference in the default Purple Glassmorphism theme, and correct for the other 6 colour themes too, rather than a hardcoded hex that would look wrong everywhere else). Top corners are moderately rounded; it sits flush with the device's bottom edge, with the safe-area inset as internal bottom padding.

## 5. Spin — its destination

The spec requires each item to have "its own functional navigation destination." Rather than invent a new coin-reward mechanic with its own backend and legal surface, `app/(tabs)/spin.tsx` is a real screen that fronts the **existing, backend-confirmed** earning features: a hero with the wheel artwork, and a card that opens **Ads Earning** (`/ads-earning`, verified server-side before anything is credited) plus a note that the **Daily Reward** already appears automatically on Home. It never shows a reward or amount the server hasn't actually given.

## 6. Verification

- `npx tsc --noEmit` — clean.
- `npm run check:currency` — clean (106 files; `NavIcons.tsx` added to the guard's allow-listed SVG files, alongside `theme/icons.tsx`, with the same "shapes only, no coin/currency imagery" rule).
- Backend guards that also scan mobile code — `AdminUiDesignSystemTest` (12/12) and `NoRawCurrencyOrAdHocCoinSvgTest` (3/3) — both pass.
- **Browser QA**, `qa-report/bottom-nav-redesign/results.log` — the real app code rendered via react-native-web in headless Chromium against the real backend (not an Android emulator): **13/13 passed** — exactly 4 tabs in the right order and labels; Home selected on launch; unselected icons are hollow (`fill="none"`); tapping Leaderboard selects it, deselects Home, and the filled layer reaches full opacity; Spin opens its own real screen with a working link into Ads Earning; Profile opens; re-tapping the current tab doesn't break the screen; scrolling never changes the selected tab; zero console/page errors. Screenshots: `01-home-selected.png`, `02-leaderboard-selected.png`, `03-spin-screen.png`, `04-profile-selected.png`.

## 7. Not verified (environment limitation)

No Android/iOS device or emulator was available in this environment (same constraint as earlier features in this project), so the **haptic on tap** and the bar's exact on-device look (react-native-web renders the same component tree and the same SVGs, but is not a substitute for a native screen) have **not** been confirmed on real hardware. Everything else above — the component logic, the icon geometry, the animation timing and the four destinations — has been.
