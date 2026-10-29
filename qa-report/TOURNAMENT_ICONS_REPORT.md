# Tournament cards — Prize Pool / Per Kill / Entry Fee icons

Date: 2026-10-29 · Branch: `claude/lucid-volta-ispwb4`

## What changed (supersedes the earlier lettered-badge version)

The first pass used badges with the words baked in ("PRIZE POOL", "KILL", "ENTRY FEE" as part of
the artwork). The product owner then supplied new, textless artwork — a trophy, a crowned skull
and a wallet, with no lettering — and asked for it to be used at a smaller size, with the app
drawing the labels itself. That is what this pass replaces it with.

- `mobile/scripts/extract-metric-icons.py` (new; replaces and deletes `extract-metric-badges.py`)
  cuts the three icons out of the supplied `Trophy, Skull, and Wallet Icons.png` — only the white
  background is removed (flood fill from the edge, then the white blended into the anti-aliased
  edge is unmixed so there's no halo on a dark card). Nothing is redrawn or cropped. Output:
  `mobile/assets/icons/prize-pool.png` (256×226), `per-kill.png` (256×235), `entry-fee.png`
  (256×228) — all transparent PNGs, same filenames and `Artwork` keys as before so nothing else
  had to change.
- `TournamentMetric` now draws the icon, then the label ("Prize Pool" / "Per Kill" / "Entry Fee",
  shown uppercase) as real text, then the amount — restoring the native text label the artwork no
  longer carries. Screen readers hear the same "Prize Pool: 100 coins" they did before.
- The icon box on cards was reduced from 60px to 36px, and on the details screen from 72px to
  44px (`ARTWORK_SIZE.metric` / `.metricDetail` in `theme/artwork.tsx`), since the icon no longer
  needs to carry a lettered banner at the bottom.
- The three icons have slightly different natural shapes (a trophy bowl vs. a skull vs. a wallet),
  so fitting them to one box width — the same convention the app already uses for the medal icons
  — gives heights 1px apart (32/33/32 at the 36px card size); none are stretched to force an exact
  match, which the project's own rule against distorting supplied artwork rules out.

## Verification actually run

The app's real React Native code rendered by react-native-web in Chromium against the local
backend — **not an Android device or emulator** (none is available in this environment).

| Check | Result |
|---|---|
| `npx tsc --noEmit` | clean |
| `npm run check:currency` | clean |
| Backend guards over the mobile code (`NoRawCurrencyOrAdHocCoinSvgTest`, `AdminUiDesignSystemTest`) | 15 passed |
| Card and details sweep — `results.log` | 109 passed, 0 failed |

The sweep, at 360×640, 390×844 and 412×915, on the Upcoming, Ongoing and Completed tabs: every
card has the three icons in order, each its own supplied artwork and loaded, sharing one box
width; each tile shows its own code-drawn label exactly once (no duplicate and no missing label);
every card's amounts match the API's values exactly; amounts fit on one line; no horizontal
scroll; the details screen shows the same three icons and labels. A fourth pass injects very large
amounts (1,234,567.50 / 12,500.75 / 99,999) into the API responses in the browser only (the
database is not touched) and confirms they are shown in full, not wrapped or cut off, on cards and
on the details screen.

Screenshots: `qa-report/tournament-icons/*.webp`, including the extracted icons on a dark
background. `qa-report/tournament-badges/` (the earlier lettered-badge version) has been
replaced by this folder.
