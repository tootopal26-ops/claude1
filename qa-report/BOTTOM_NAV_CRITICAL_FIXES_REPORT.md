# Bottom Nav — Critical Fixes: Duplicate Icon Bug, Indicator Position, Light Shape

**Scope:** the bottom nav bar only (`mobile/src/components/navigation/BottomNav.tsx`). No
other screen, destination, or icon design was touched — this is a bug-fix pass on the existing
component, per the brief, not a redesign.

This addresses two user reports in sequence: a first pass asking the purple light's visible
rectangular edge to be fixed, and a follow-up ("CRITICAL") report that caught a real rendering
bug — both selected and unselected icon layers staying visible at once — plus asking for more
breathing room between the indicator line and the icon.

## 1. CRITICAL — the duplicate-icon bug (root cause + fix)

**Root cause:** the crossfade between the outline and filled icon was only half-built. The
filled layer's opacity was animated (`0 → 1` as a tab is selected), but the outline layer
underneath it was a plain `View` with **no opacity animation at all** — always fully opaque.
So once a tab was selected, both the outline (always opacity 1) and the filled icon (now also
opacity 1) were stacked and visible simultaneously. Since the two are separately-traced paths
(not one shape with a fill toggle), their edges don't coincide exactly, so the outline showed
through/around the solid icon — exactly the "duplicate icon" reported.

**Fix:** the outline layer's opacity is now `1 - active` (animated on the same `active` value
driving the filled layer), so at rest it is `(1, 0)` unselected and `(0, 1)` selected — never
both on. Mid-tap, both are partially visible, which is the intended crossfade, not a bug.

**Verified directly**, not just inferred: a new regression check reads each tab's two icon
layers' *actual rendered opacity* (not just "the filled layer reached 1") for all four tabs in
both their selected and unselected states — see §4.

## 2. Indicator line position — now with a real, visible gap

The bar's row gained a 24px top strip (`INDICATOR_ZONE`) reserved for the line and light. The
icon/label block is centered by flexbox within the row, so adding height there moves the icon
down by *half* that amount, not the full amount — a detail the first attempt got wrong (it added
the zone but kept the pill's old, pre-zone vertical offset, so the pill drifted out of alignment
with the icon). This pass derives every vertical position analytically from the icon's actual
centered position instead of a hand-tuned offset, so pill/line/light can't drift apart from the
icon again:

```
ICON_TOP  = (BAR_ROW_HEIGHT − CONTENT_BLOCK_HEIGHT) / 2        (same centering React Native does)
PILL_TOP  = ICON_TOP − (PILL_HEIGHT − ICON_SIZE) / 2            (pill centred on the icon)
LIGHT_TOP = LINE_TOP; LIGHT_HEIGHT = PILL_TOP − LINE_TOP − 3     (light stops 3px clear of the pill)
```

**Measured on the real rendered bar** (Playwright `getBoundingClientRect`, not estimated):

| Element | Top | Bottom |
|---|---:|---:|
| Purple line | 4 | 7 |
| Purple light (box) | 4 | 16.5 |
| Dark pill | 19.5 | 51.5 |
| Icon | 23.5 | 47.5 |

Line-to-icon gap: **16.5px** — clearly visible, never touching. Pill is now centred exactly on
the icon (both share vertical centre 35.5) and fully surrounds it, where before it sat 5.5px
too high, leaving the icon's top sticking out above the pill.

## 3. Purple light — no more visible rectangle

**Root cause of the "ugly rectangular glow":** the gradient used a single `r="75%"` radius with
`preserveAspectRatio="none"` on a box barely bigger than the glow itself — on a wide, short box
that stretches a circular gradient into a hard-edged ellipse that doesn't fade to true-zero
before the box's own corners, so the box's own edge was visible as a rectangle.

**Fix**, matching the same technique the app's own `AmbientGlow` component already uses
successfully elsewhere in this codebase: separate `rx`/`ry` (never a single `r`), and a box
generously bigger than the visible glow so the fade to fully transparent happens with real
margin to spare, not right at the edge. No masks or filters (consistent with this project's
Android-rendering rule); pure gradient math. The light also now stops a full 3px short of the
pill (see §2's table — light ends at 16.5, pill starts at 19.5), so it reads as light falling
onto the space above the icon rather than a background wash behind it.

## 4. Verification

- `npx tsc --noEmit` — clean.
- `npm run check:currency` — clean (106 files).
- Backend guards that also scan mobile code — `AdminUiDesignSystemTest` (12/12) and
  `NoRawCurrencyOrAdHocCoinSvgTest` (3/3) — both pass.
- **Real geometry measurements** (`qa-report/bottom-nav-critical-fixes/geometry-measurements.log`)
  — `getBoundingClientRect` on the actual rendered icon, line, and pill elements, not estimated
  from flexbox theory, confirming the table in §2 and that the outline/filled icon opacities are
  exactly `0`/`1` (never both non-zero) once a selection settles.
- **Browser QA**, `qa-report/bottom-nav-critical-fixes/results.log` — real app code via
  react-native-web in headless Chromium against the real backend: **17/17 passed**, including a
  new check that reads every tab's two icon layers' actual rendered opacity for **all four
  tabs** (Leaderboard, Spin, Home, Profile), both selected and unselected — the direct
  regression test for the duplicate-icon bug, not just a visual impression.
- **Screenshots**, close-cropped on the nav bar for all four selected states
  (`navbar-crit-{home,leaderboard,spin,profile}.png`) plus the full-screen captures — visually
  confirm: exactly one icon per tab, a clearly visible gap above the icon, a soft downward glow
  with no rectangular edge, and the pill correctly centred and never overlapping the line.

## 5. Not verified (environment limitation)

No Android/iOS device or emulator was available, so the bar's exact on-device rendering and the
selection haptic are not confirmed on real hardware — only via react-native-web in a browser, as
above (same limitation noted in the two earlier nav reports).
