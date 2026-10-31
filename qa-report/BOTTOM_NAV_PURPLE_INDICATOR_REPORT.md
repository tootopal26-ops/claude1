# Premium Esports Nav Bar — Exact Reference Icons + Purple Active Indicator

**Scope:** the bottom nav bar only (`mobile/src/components/navigation/BottomNav.tsx`,
`NavIcons.tsx`). Builds directly on the earlier WhatsApp-style redesign
(`qa-report/BOTTOM_NAV_REDESIGN_REPORT.md`); nothing else in the app was touched.

## 1. Icons — traced from the supplied reference, not redrawn

The user supplied a reference image (8 icons: outline row + filled row — trophy, spin wheel,
home, profile). The brief was explicit: *"Use my provided image as the exact visual reference.
Do not redesign, replace, modify or generate alternative icon designs."*

Rather than eyeball the reference and hand-draw an approximation, each of the 8 icons was
**vector-traced directly from the supplied image** with `potrace`:

1. The reference was split into its 8 cells and each cell's alpha channel thresholded to a
   clean black/white mask.
2. `potrace` traced each mask to an SVG path — the literal silhouette from the image, not a
   redrawn copy of it.
3. Each icon's outline+filled pair was cropped to a shared, tightly-padded square `viewBox`
   (computed from the union of both states' bounding boxes), so the four icons read at a
   consistent size and the two states stay pixel-aligned for the crossfade.

New `mobile/src/components/navigation/NavIcons.tsx` embeds these exact traced paths (one
`<Path>` + a shared `translate/scale` transform per icon/state) — pure white (`#ffffff`),
no category colours, matching the reference's silhouette exactly. `BottomNav.tsx` renders
them unmodified via `<NavIcon name filled size>`.

**A real bug was caught and fixed during this work:** `potrace`'s default convention treats
*dark* pixels as the foreground to trace. The masks had the icon drawn in *white*, so the
first trace inverted every icon — it silhouetted the background instead, producing a solid
white box with the icon as a dark cutout. This was caught by rendering the traced paths in
isolation (not just in the small on-screen nav bar, where it was easy to misread) before
wiring them into the app, fixed with potrace's `-i` (invert) flag, and re-verified the same
way before touching `BottomNav.tsx` again — see §4.

## 2. Purple active indicator + downward light

Above the selected icon:
- **Indicator line:** solid `#A855F7`, 3px tall, rounded ends, ~38% of the item's width,
  centred, with a small gap above the icon.
- **Downward light:** a `RadialGradient` anchored at the line's top edge only (`cy="0%"`),
  so it radiates down and slightly outward — never a ring or circle around the icon. Stops at
  the exact requested values: centre `rgba(168,85,247,0.30)` → middle `rgba(168,85,247,0.12)`
  → ending `rgba(168,85,247,0)`. It fades out by the icon rather than washing over the whole
  pill, and renders behind the (opaque) icon layers so it never reduces their visibility.

Both travel together with the existing dark-grey pill on one shared spring-animated
`translateX` (no overshoot) — one spring, three shapes, always in sync.

## 3. Animation

- Icon scale: 1.0 → **1.12** → 1.0 (updated from the prior redesign's 1.15, per this brief's
  exact value), `Animated.timing` out then a no-overshoot `Animated.spring` back to 1.0.
- Outline↔filled crossfade: opacity, **300ms**, `EASE.inOut`.
- Pill + line + light: one `Animated.spring` (`SPRING` token — settles without visible bounce).
- No rotation, no flashing, no double-pop; unchanged under `useReducedMotion()` (direct
  `setValue`, no animation).

## 4. Container

Full-width, no side margins, moderately rounded top corners, no border/shadow/glassmorphism.
Background is the literal `#17111F` the brief specified twice now (this is also the Mini Max
dark theme's real canvas colour used elsewhere in the project) — a deliberate, explicit
exception to the mobile app's own per-theme token system for this one fixed bar, not an
oversight; it does not change across the app's 7 colour themes.

## 5. Verification

- `npx tsc --noEmit` — clean.
- `npm run check:currency` — clean (106 files; `BottomNav.tsx` re-added to the guard's
  allow-listed inline-`<Svg>` files for the gradient light, same "shapes/gradients only, no
  coin or currency imagery" rule as before).
- Backend guards that also scan mobile code — `AdminUiDesignSystemTest` (12/12) and
  `NoRawCurrencyOrAdHocCoinSvgTest` (3/3) — both pass.
- **Isolated path verification** (before touching the app): every traced path was rendered
  standalone, at both its full un-cropped canvas and its final cropped `viewBox`, to catch
  exactly the inversion bug described in §1 before it could reach the real nav bar.
- **Browser QA**, `qa-report/bottom-nav-purple-indicator/results.log` — the real app code via
  react-native-web in headless Chromium against the real backend: **13/13 passed** (4 tabs in
  order, hollow-vs-filled icon states, selection/deselection, Spin's own screen, re-tap safety,
  no selection change on scroll, zero console errors). Screenshots:
  `01-home-selected.png`, `02-leaderboard-selected.png` (cropped nav-bar close-ups confirm the
  purple line + downward glow render correctly above the selected icon), `03-spin-screen.png`,
  `04-profile-selected.png`.

## 6. Not verified (environment limitation)

No Android/iOS device or emulator was available, so the selection haptic and the bar's exact
on-device rendering have **not** been confirmed on real hardware — only via react-native-web
in a browser, as above (same limitation as the prior nav redesign).
