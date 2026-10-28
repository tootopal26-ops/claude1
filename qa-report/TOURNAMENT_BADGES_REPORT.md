# Tournament cards — Prize Pool / Kill / Entry Fee badges

Date: 2026-10-28 · Branch: `claude/lucid-volta-ispwb4`

## What changed

The original request asked for the words inside the supplied badges to be removed and the labels drawn by
the app. Partway through, the instruction changed to: **keep the words on the badges and remove the app's own
text labels instead.** That is what was built.

- `mobile/scripts/extract-metric-badges.py` cuts the three badges out of the supplied
  `Esports Prize, Kill, and Entry Fee Badges.png` whole. Only the white background is removed (flood fill
  from the edge, then the white blended into the anti-aliased edge is unmixed so there is no halo on dark
  cards). Nothing is redrawn. Output: `mobile/assets/icons/prize-pool.png`, `per-kill.png`, `entry-fee.png`
  (238/237/229 × 256, transparent PNG).
- `TournamentMetric` (new, `mobile/src/components/tournament/`) shows one badge with the tournament's amount
  under it. No visible "Prize pool / Per kill / Entry" text; screen readers hear "Prize pool: 100 coins",
  "Per kill: 1 coins", "Entry fee: Free".
- Used by `TournamentCard` (every Upcoming, Ongoing and Completed list, a game's list and My matches) and the
  tournament details screen. Badges are 60 px on cards and 72 px on details. Amounts come from the API
  unchanged; a free entry still shows "Free". A very long amount gets a smaller font fitted to the tile's
  measured width (and `adjustsFontSizeToFit` on Android), so it never wraps, spills or gets cut off.
- Amounts are shown in coins with the coin artwork, as everywhere else in the app (the project bans a raw ₹
  sign in player-facing UI).

## Verification actually run

The app's real React Native code rendered by react-native-web in Chromium against the local backend — **not
an Android device or emulator** (none is available in this environment).

| Check | Result |
|---|---|
| `npx tsc --noEmit` | clean |
| `npm run check:currency` | clean |
| Backend guards over the mobile code (`NoRawCurrencyOrAdHocCoinSvgTest`, `AdminUiDesignSystemTest`) | 15 passed |
| Card and details sweep — `results.log` | 109 passed, 0 failed |

The sweep, at 360×640, 390×844 and 412×915, on the Upcoming, Ongoing and Completed tabs: every card has the
three badges in order; each is its supplied artwork and loaded; badges share one height; no coded label text
sits next to them; every card's amounts match the API's values exactly; amounts fit on one line; no
horizontal scroll; the details screen shows the same three badges. A fourth pass injects very large amounts
(1,234,567.50 / 12,500.75 / 99,999) into the API responses in the browser only (the database is not touched)
and confirms they are shown in full, not wrapped or cut off, on cards and on the details screen. That pass
first found the details screen cutting "1,234,567.50" short; the tiles were given more width and a lower
minimum font size before the final run.

Screenshots: `qa-report/tournament-badges/*.webp`, including the extracted badges on a dark background.
