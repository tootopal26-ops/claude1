# Supplied icon artwork — manifest

Every colourful icon in the app is the product owner's supplied artwork, cut out of the
supplied sheets by `mobile/scripts/extract-alpha-icons.py` (the method is documented at
the top of that script). Nothing here is redrawn or generated. The app loads them only
through `<Artwork name="…">` (`src/theme/artwork.tsx`), which keeps each image at its
own aspect ratio so nothing is stretched or clipped. The coin stays `assets/coin.png`
via `<CoinAsset>`.

Sources:

* **Sheets** — `11.png`, `12.png`, `13.png` from the first ZIP (transparent PNGs, 3464 px).
* **Photo** — `photo_2026-10-23_21-20-56.jpg` from the second ZIP (black background):
  used only for the five icons that appear nowhere else.
* **Badges** — `Esports Prize, Kill, and Entry Fee Badges.png` from the third ZIP (white
  background): the three tournament-metric badges, cut out whole by
  `mobile/scripts/extract-metric-badges.py` (only the white background is removed; the words on
  each badge are part of the artwork and stay, so the app draws no separate label for them).

| File | `Artwork` name | Size (px) | Source | Used for |
|---|---|---|---|---|
| `icons/profile.png` | `profile` | 192×188 | Sheets — person | Profile › My Profile |
| `icons/wallet.png` | `wallet` | 188×192 | Sheets — wallet with coins | Profile › Wallet |
| `icons/bell.png` | `bell` | 152×155 | Photo — yellow bell without a dot | Home header bell (the red unread dot is drawn by the app from the real unread count), Profile › Notifications |
| `icons/redeem.png` | `redeem` | 192×166 | Sheets — blue crown ticket | Profile › Redeem Code; the ticket on free-tournament ticket progress |
| `icons/settings.png` | `settings` | 192×190 | Sheets — gear | Profile › Settings |
| `icons/terms.png` | `terms` | 192×188 | Sheets — document, shield and lock | Profile › Terms and privacy |
| `icons/support.png` | `support` | 179×192 | Sheets — headset agent | Profile › Customer Support |
| `icons/ads-earning.png` | `adsEarning` | 192×161 | Sheets — video, coins and megaphone | Profile › Ads Earning |
| `icons/matches.png` | `matches` | 187×192 | Sheets — crossed swords | Profile › Total Matches |
| `icons/logout.png` | `logout` | 192×187 | Sheets — red logout | Profile › Log out |
| `icons/deposit.png` | `deposit` | 228×256 | Sheets — wallet with green down arrow | Deposit (green) buttons |
| `icons/withdraw.png` | `withdraw` | 254×256 | Sheets — cash with up arrow | Withdraw (red) buttons |
| `icons/calendar.png` | `calendar` | 165×144 | Photo — calendar without a clock | Tournament start time (cards and details) |
| `icons/medal-1.png` | `medal1` | 256×232 | Sheets — gold medal | Leaderboard and results rank 1 |
| `icons/medal-2.png` | `medal2` | 256×232 | Sheets — silver medal | Rank 2 |
| `icons/medal-3.png` | `medal3` | 256×236 | Sheets — bronze medal | Rank 3 (ranks 4–100 show `#4` … `#100`) |
| `icons/mode-solo.png` | `modeSolo` | 148×133 | Photo — Solo | Tournament mode chip |
| `icons/mode-duo.png` | `modeDuo` | 171×141 | Photo — Duo | Tournament mode chip |
| `icons/mode-squad.png` | `modeSquad` | 182×147 | Photo — Squad | Tournament mode chip |
| `icons/prize-pool.png` | `prizePool` | 238×256 | Badges — trophy with "PRIZE POOL" banner | Tournament card and details: prize pool (amount under it) |
| `icons/per-kill.png` | `perKill` | 237×256 | Badges — crowned skull with "KILL" banner | Tournament card and details: per-kill reward |
| `icons/entry-fee.png` | `entryFee` | 229×256 | Badges — wallet with "ENTRY FEE" banner | Tournament card and details: entry fee (or Free) |
| `status/ongoing.png` | `statusOngoing` | 320×206 | Sheets — ONGOING artwork, baked label removed | Home status card (label and count are real text) |
| `status/upcoming.png` | `statusUpcoming` | 320×237 | Sheets — UPCOMING artwork, baked label removed | Home status card |
| `status/completed.png` | `statusCompleted` | 320×238 | Sheets — COMPLETED artwork, baked label removed; the circle's hidden lower rim completed from its own pixels | Home status card |
| `nav/home.png` | `navHome` | 144×132 | Sheets — home | Bottom navigation |
| `nav/leaderboard.png` | `navLeaderboard` | 144×138 | Sheets — leaderboard | Bottom navigation |
| `nav/profile.png` | `navProfile` | 127×144 | Sheets — profile | Bottom navigation |

All files are RGBA PNGs with real transparency. To regenerate them from the originals:

```
python3 -I mobile/scripts/extract-alpha-icons.py <folder with 11.png 12.png 13.png> <photo .jpg> mobile/assets
python3 -I mobile/scripts/extract-metric-badges.py "<Esports Prize, Kill, and Entry Fee Badges.png>" mobile/assets
```

The contact sheets themselves are never shipped or shown in the app.
