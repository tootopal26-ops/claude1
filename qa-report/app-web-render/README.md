# Mini Max app — UI captures (web render, NOT Android)

> **What these are.** Every image here is the real app code (`mobile/app`, `mobile/src`) rendered by **react-native-web in headless Chromium**, talking to the real local Laravel backend with QA data. They are **not** Android emulator or device screenshots: this environment has no hardware virtualisation (`/dev/kvm`) and its network policy blocks the Android SDK host (`dl.google.com`), so no emulator could run. Native-only behaviour (animation smoothness on device, haptics, the system Back key, TalkBack, push taps, the UPI app hand-off) is **not** shown or claimed here.

Generated 2026-10-23T12:48:42.966Z (UI repair pass) · 70 captures · 55/55 automated checks passed · 0 problems · axe (WCAG 2.1 A/AA) violations on the six key screens: 0.

**QA data.** Created only through the app's own domain services in the local QA database (wallet top-ups through the ledger, joins through the join action, room release, result publishing, Home banners through the banner service). Tournaments were created as records the way Admin's form creates them, with titles prefixed `[RD]`; the stress captures add an 8-digit balance through the wallet ledger and one very long tournament title, and the database snapshot is restored afterwards; banner artwork is labelled *QA test artwork*. Start times of the played matches were moved into the past to simulate time passing (timestamps only, no money moved). Captures marked *simulated* had one request delayed or failed by the test harness to show a loading or error state.

**Supplied artwork.** The bottom-navigation icons and the Ongoing / Upcoming / Completed badges are the user-supplied "Glossy Neon Navigation Icon Set" and "Ongoing Upcoming Completed Gaming Badges", cut out of their sheets (see `mobile/scripts/extract-checkerboard-icons.py`). The coin is the existing coin PNG. Nothing in these captures is generated or mocked artwork apart from the banner images labelled *QA test artwork*.

Contact sheets: [contact-sheet-01-phone.webp](contact-sheet-01-phone.webp), [contact-sheet-02-phone.webp](contact-sheet-02-phone.webp), [contact-sheet-03-phone.webp](contact-sheet-03-phone.webp), [contact-sheet-04-phone.webp](contact-sheet-04-phone.webp), [contact-sheet-05-small-360x640.webp](contact-sheet-05-small-360x640.webp), [contact-sheet-06-large-430x932.webp](contact-sheet-06-large-430x932.webp), [contact-sheet-07-tablet-768x1024.webp](contact-sheet-07-tablet-768x1024.webp), [contact-sheet-08-themes.webp](contact-sheet-08-themes.webp), [contact-sheet-09-stress.webp](contact-sheet-09-stress.webp)


## Phone 390×844

| # | Screen / state | Route | What it shows | Viewport |
|---|---|---|---|---|
| 01 | [Sign in](01-login.webp) | `/login` | Real logo, Sign in / Create account tabs, labelled fields, password show/hide. | 390×844 |
| 02 | [Sign in — empty submit](02-login-field-errors.webp) | `/login` | Each field shows its own error; nothing is sent to the server. | 390×844 |
| 03 | [Sign in — wrong password (server message, password shown)](03-login-server-error.webp) | `/login` | The server's own message under the field it belongs to; the eye toggle reveals the password. | 390×844 |
| 04 | [Create account](04-register.webp) | `/login` | Username, email, date of birth, state, mobile, password, terms checkbox. | 390×844 (full height) |
| 05 | [Create account — empty submit](05-register-errors.webp) | `/login` | Field-level errors, including the terms checkbox. | 390×844 (full height) |
| 06 | [Boot — checking the saved session](06-boot-checking-session.webp) | `/` | Real logo at the splash size, quiet spinner. (/auth/me delayed 4 s by the test harness to make this visible.) *(simulated: /auth/me delayed 4 s)* | 390×844 |
| 07 | [Boot — server unreachable](07-boot-offline.webp) | `/` | Says so and offers Retry; the player is NOT signed out. (/auth/me failed by the test harness.) *(simulated: /auth/me network failure)* | 390×844 |
| 08 | [Home](08-home.webp) | `/` | Header (green avatar ring, Welcome Back, rounded-rectangle wallet tile with the coin artwork, rounded-square bell with the unread dot) · 16:9 hero · the supplied Ongoing / Upcoming / Completed badges with server counts · games. | 390×844 |
| 09 | [Home — whole screen](09-home-full.webp) | `/` | Games grid: 16:9 art with the title and the green dot + upcoming count inside its bottom edge over a fade (grey dot and 0 when a game has none). | 390×844 (full height) |
| 10 | [Home hero — mid auto-advance](10-home-hero-transition.webp) | `/` | Outgoing banner slides left and dims; edges soften only during the change. | 390×844 |
| 11 | [Tournaments — Upcoming](11-tournaments-upcoming.webp) | `/tournaments?status=upcoming` | Back header, status tabs with server counts, tournament cards. | 390×844 |
| 12 | [Tournaments — Upcoming (whole list)](12-tournaments-upcoming-full.webp) | `/tournaments?status=upcoming` | Join / Joined / Full states; Rules only where the tournament has rules. | 390×844 (full height) |
| 13 | [Rules sheet](13-rules-sheet.webp) | `/tournaments` | The rules exactly as staff wrote them. | 390×844 |
| 14 | [Join — squad players](14-join-players.webp) | `/tournaments` | Four in-game names for a squad (player 1 is you); UID optional. | 390×844 (full height) |
| 15 | [Join — missing names](15-join-players-errors.webp) | `/tournaments` | Each empty name is flagged before anything is sent. | 390×844 (full height) |
| 16 | [Join — review and confirm](16-join-review.webp) | `/tournaments` | Fee beside the current balance and the balance after joining. Nothing is charged yet. | 390×844 (full height) |
| 17 | [Join — confirmed by the server](17-join-success.webp) | `/tournaments` | Shown only from the server's response: fee paid and the registered players. | 390×844 (full height) |
| 18 | [Tournaments — Ongoing](18-tournaments-ongoing.webp) | `/tournaments?status=ongoing` | Live status; joined players get Room details. | 390×844 |
| 19 | [Tournament — room ID and password](19-tournament-live-credentials.webp) | `/tournament/8` | Only for joined players, once released; each value has Copy. | 390×844 (full height) |
| 20 | [Room ID copied](20-tournament-copied.webp) | `/tournament/8` | Copy confirms with "Copied". | 390×844 |
| 21 | [Tournaments — Completed](21-tournaments-completed.webp) | `/tournaments?status=completed` | Completed and cancelled; Results on each. | 390×844 (full height) |
| 22 | [Results — published standings](22-results.webp) | `/tournament/9/results` | Rank, rings for the top three, in-game names, kills and the prize the server awarded; you are highlighted. | 390×844 (full height) |
| 23 | [Tournament — completed, your result](23-tournament-completed-details.webp) | `/tournament/9` | Rank, kills and prize from the server. | 390×844 (full height) |
| 24 | [Results — not yet published](24-results-pending.webp) | `/tournament/10/results` | No provisional or invented standings. | 390×844 |
| 25 | [Tournament — upcoming, open to join](25-tournament-upcoming.webp) | `/tournament/5` | Artwork, chips, rewards, slots, join cut-off, rules, Join. | 390×844 (full height) |
| 26 | [Tournament — joined, room not released yet](26-tournament-joined-waiting.webp) | `/tournament/4` | Waiting state instead of blanks. | 390×844 (full height) |
| 27 | [Game — Portrait Poker tournaments](27-game-tournaments.webp) | `/game/13` | One game's tournaments (by its ID); a full tournament offers a disabled Full. | 390×844 |
| 28 | [Leaderboard — Daily](28-leaderboard-daily.webp) | `/leaderboard` | Hero · Daily/Weekly/Monthly · "Provisional standings" chip (no "live" label) · podium 2·1·3 · ranks from 4. | 390×844 |
| 29 | [Leaderboard — Daily (whole screen)](29-leaderboard-daily-full.webp) | `/leaderboard` | Earnings with the coin artwork on the right; your row tinted. | 390×844 (full height) |
| 30 | [Leaderboard — Weekly](30-leaderboard-weekly.webp) | `/leaderboard` | Weekly standings from the server. | 390×844 |
| 31 | [Leaderboard — Monthly](31-leaderboard-monthly.webp) | `/leaderboard` | Monthly standings from the server. | 390×844 |
| 32 | [Leaderboard — locked by Admin price](32-leaderboard-locked.webp) | `/leaderboard` | Banners stay; the unlock card replaces the rankings (server decides). | 390×844 |
| 33 | [Unlock — confirmation sheet](33-leaderboard-unlock-confirm.webp) | `/leaderboard` | Nothing is spent until the player confirms (not confirmed here). | 390×844 |
| 34 | [Profile](34-profile.webp) | `/profile` | Avatar ring, name, real stats, then the menu; Support is omitted (no backend feature). | 390×844 |
| 35 | [Profile — scrolled to the end](35-profile-end.webp) | `/profile` | Terms and privacy, then Log out last. | 390×844 |
| 36 | [Log out — confirmation](36-logout-confirm.webp) | `/profile` | Logout always asks first. | 390×844 |
| 37 | [Redeem Code — server refusal](37-redeem-error.webp) | `/profile` | Uppercased, 5 characters, red Redeem; the message is the server’s. | 390×844 |
| 38 | [My Profile](38-my-profile.webp) | `/account` | Account details from /auth/me and signed-in devices (each can be signed out). | 390×844 (full height) |
| 39 | [Wallet](39-wallet.webp) | `/wallet` | The one shared balance, deposit and withdrawable winnings, actions, history. | 390×844 (full height) |
| 40 | [Add coins](40-deposit.webp) | `/deposit` | UPI deposit form and history (status chips no longer clipped). | 390×844 (full height) |
| 41 | [Withdraw](41-withdraw.webp) | `/withdraw` | Withdrawable balance, form, history. | 390×844 (full height) |
| 42 | [Notifications](42-notifications.webp) | `/notifications` | Unread tinted with a dot; filled category icons; tournament notifications open the tournament. | 390×844 (full height) |
| 43 | [Settings](43-settings.webp) | `/settings` | Colour and font themes (existing feature, kept) in grouped cards; the selected option is ticked. | 390×844 (full height) |
| 44 | [Terms and privacy](44-legal.webp) | `/legal` | Current legal documents from the server: version and published date, Terms / Privacy switch. A failed load offers Try again instead of claiming nothing is published. | 390×844 |
| 45 | [Wallet — history failed to load](45-wallet-history-error.webp) | `/wallet` | Balance still shown; history says so and offers Try again. (/wallet/ledger failed by the harness.) *(simulated: /wallet/ledger network failure)* | 390×844 |
| 46 | [Home — games failed to load](46-home-games-error.webp) | `/` | The rest of Home (balance, hero, counts) still works. (/games failed by the harness.) *(simulated: /games network failure)* | 390×844 (full height) |
| 47 | [Tournaments — failed to load](47-tournaments-error.webp) | `/tournaments` | Plain message and Try again. (/tournaments failed by the harness.) *(simulated: /tournaments network failure)* | 390×844 |
| 48 | [Home — reduced motion](48-home-reduced-motion.webp) | `/` | System "reduce motion" on: no autoplay, no ring pulse; swipe still works. | 390×844 |

## Small phone 360×640

| # | Screen / state | Route | What it shows | Viewport |
|---|---|---|---|---|
| 49 | [Home @ 360×640](49-home-small-360x640.webp) | `/` | Content capped at 560 dp and centred on wide screens. | 360×640 (full height) |
| 50 | [Tournaments @ 360×640](50-tournaments-small-360x640.webp) | `/tournaments` |  | 360×640 |
| 51 | [Leaderboard @ 360×640](51-leaderboard-small-360x640.webp) | `/leaderboard` |  | 360×640 (full height) |
| 52 | [Profile @ 360×640](52-profile-small-360x640.webp) | `/profile` |  | 360×640 |
| 53 | [Profile @ 360×640 — scrolled to the end](53-profile-end-small-360x640.webp) | `/profile` |  | 360×640 |

## Large phone 430×932

| # | Screen / state | Route | What it shows | Viewport |
|---|---|---|---|---|
| 54 | [Home @ 430×932](54-home-large-430x932.webp) | `/` | Content capped at 560 dp and centred on wide screens. | 430×932 (full height) |
| 55 | [Tournaments @ 430×932](55-tournaments-large-430x932.webp) | `/tournaments` |  | 430×932 |
| 56 | [Leaderboard @ 430×932](56-leaderboard-large-430x932.webp) | `/leaderboard` |  | 430×932 (full height) |
| 57 | [Profile @ 430×932](57-profile-large-430x932.webp) | `/profile` |  | 430×932 |
| 58 | [Profile @ 430×932 — scrolled to the end](58-profile-end-large-430x932.webp) | `/profile` |  | 430×932 |

## Tablet 768×1024

| # | Screen / state | Route | What it shows | Viewport |
|---|---|---|---|---|
| 59 | [Home @ 768×1024](59-home-tablet-768x1024.webp) | `/` | Content capped at 560 dp and centred on wide screens. | 768×1024 (full height) |
| 60 | [Tournaments @ 768×1024](60-tournaments-tablet-768x1024.webp) | `/tournaments` |  | 768×1024 |
| 61 | [Leaderboard @ 768×1024](61-leaderboard-tablet-768x1024.webp) | `/leaderboard` |  | 768×1024 (full height) |
| 62 | [Profile @ 768×1024](62-profile-tablet-768x1024.webp) | `/profile` |  | 768×1024 |
| 63 | [Profile @ 768×1024 — scrolled to the end](63-profile-end-tablet-768x1024.webp) | `/profile` |  | 768×1024 |

## Colour themes

| # | Screen / state | Route | What it shows | Viewport |
|---|---|---|---|---|
| 64 | [Home — Cyber Blue theme](64-home-theme-cyber-blue.webp) | `/` | Every colour comes from the selected theme tokens. | 390×844 |
| 65 | [Leaderboard — Cyber Blue theme](65-leaderboard-theme-cyber-blue.webp) | `/leaderboard` | Rank rings keep their gold / silver / bronze / green meaning in every theme. | 390×844 |
| 66 | [Home — Sunset Gold theme](66-home-theme-sunset-gold.webp) | `/` | Every colour comes from the selected theme tokens. | 390×844 |
| 67 | [Leaderboard — Sunset Gold theme](67-leaderboard-theme-sunset-gold.webp) | `/leaderboard` | Rank rings keep their gold / silver / bronze / green meaning in every theme. | 390×844 |

## Stress (8-digit balances, very long titles) @ 360×640

| # | Screen / state | Route | What it shows | Viewport |
|---|---|---|---|---|
| 68 | [Stress — 8-digit balance on Home @ 360×640](68-stress-home.webp) | `/` | The balance is never cut; the greeting gives way first. | 360×640 |
| 69 | [Stress — 8-digit balance in Wallet @ 360×640](69-stress-wallet.webp) | `/wallet` | Tiles keep their size; the figure scales down instead of overflowing. | 360×640 |
| 70 | [Stress — very long title and 7-digit prize @ 360×640](70-stress-tournaments.webp) | `/tournaments` | Titles wrap to two lines; amounts stay readable. | 360×640 (full height) |

## Automated regression checks (this run)

| ID | Check | Result | Evidence |
|---|---|---|---|
| R01 | Signed-out visit to / shows Sign in (no bypass, no other screen) | pass | http://127.0.0.1:8090/login |
| R02 | Empty sign-in shows field-level errors | pass |  |
| R03 | Wrong password shows the server message, stays on sign-in | pass |  |
| R04 | Network failure at boot keeps the session; Retry signs straight back in | pass |  |
| R05 | Wallet pill shows the server total_balance | pass | Wallet balance 1,730.50 coins. Opens your wallet. |
| R06 | Ongoing shortcut (supplied badge) shows the server count | pass | Ongoing: 3 |
| R06 | Upcoming shortcut (supplied badge) shows the server count | pass | Upcoming: 4 |
| R06 | Completed shortcut (supplied badge) shows the server count | pass | Completed: 3 |
| R07 | Game card "Free Fire": title and upcoming count inside the card | pass | Free Fire 0 inside=true |
| R07 | Game card "Free Fire Showdown": title and upcoming count inside the card | pass | Free Fire Showdown 2 inside=true |
| R07 | Game card "Chess Blitz": title and upcoming count inside the card | pass | Chess Blitz 1 inside=true |
| R07 | Game card "Portrait Poker": title and upcoming count inside the card | pass | Portrait Poker 1 inside=true |
| R08 | Home order: header → hero → shortcuts → games | pass | {"header":17,"hero":84,"shortcuts":318,"games":465} |
| R09 | Bottom nav has exactly Leaderboard · Home · Profile | pass | Leaderboard \| Home \| Profile |
| R38 | Wallet control is a rounded rectangle, not a capsule | pass | {"radius":12,"h":42} |
| R39 | Bottom nav uses the three supplied icon images | pass | 3 |
| R10 | Bell reflects the real unread count | pass | Notifications, 9 unread / api 9 |
| R11 | Hero auto-advances after ~5 s | pass |  |
| R12 | Bottom nav is not shown on pushed screens | pass |  |
| R13 | List opens on the tab the shortcut asked for | pass | Upcoming, 4 |
| R14 | No deduction before confirmation | pass | 1730.50 vs 1730.50 |
| R15 | Exactly the entry fee is deducted after confirmation | pass | 1730.50 → 1690.50 |
| R16 | List refreshes: the card now says Joined | pass |  |
| R17 | A second join is refused by the server (no double charge) | pass | 422 You have already joined this tournament. |
| R18 | Copy puts the exact room ID on the clipboard | pass | 7351 9026 |
| R19 | Credentials API refuses before release (joined, not released) | pass | 404 |
| R20 | Back returns to the list (history back = Android Back on web) | pass | http://127.0.0.1:8090/tournaments?status=upcoming |
| R21 | Results screen lists every published result | pass | 8 vs 8 |
| R42 | Tournament detail artwork is a full-width 16:9 hero | pass | {"w":356,"h":199,"vw":390} |
| R22 | A full tournament cannot be joined (disabled) | pass |  |
| R23 | Podium order is 2 · 1 · 3 | pass | [138,16,260] |
| R24 | Every server entry is shown (podium + rows) | pass | 8 vs 8 |
| R25 | First place shows the server earnings | pass | Rank 1, qaplayerone, earned 250.00 coins |
| R26 | Re-tapping the active tab pushes nothing | pass |  |
| R43 | Scrolled to the end, Log out clears the bottom nav @ 390 | pass | {"logoutBottom":747,"navTop":764} |
| R27 | Profile stats equal /profile/stats | pass | {"total_earnings":"150.00","matches":1,"deposits_total":"0.00","deposits_count":0} |
| R40 | Profile order: stats → My Profile … Terms and privacy → Log out last | pass | [239,331,387,443,499,555,611,709] |
| R41 | Menu icons are filled, coloured artwork (not white outlines) | pass | 2 filled shapes in the Settings row icon |
| R28 | Cancelling logout keeps the session | pass |  |
| R29 | Wallet total equals the server balance (same figure as Home) | pass | 1,690.50 |
| R30 | Tapping a tournament notification opens that tournament | pass | http://127.0.0.1:8090/tournament/4 |
| R31 | Try again recovers once the network is back | pass |  |
| R32 | Reduced motion: hero does not auto-advance | pass |  |
| R35 | Bottom nav width stays within the content column @ 360 | pass | 328 |
| R36 | "Welcome Back" is not truncated @ 360 | pass | false |
| R43 | Scrolled to the end, Log out clears the bottom nav @ 360 | pass | {"logoutBottom":543,"navTop":560} |
| R35 | Bottom nav width stays within the content column @ 430 | pass | 398 |
| R36 | "Welcome Back" is not truncated @ 430 | pass | false |
| R43 | Scrolled to the end, Log out clears the bottom nav @ 430 | pass | {"logoutBottom":835,"navTop":852} |
| R35 | Bottom nav width stays within the content column @ 768 | pass | 528 |
| R36 | "Welcome Back" is not truncated @ 768 | pass | false |
| R43 | Scrolled to the end, Log out clears the bottom nav @ 768 | pass | {"logoutBottom":927,"navTop":944} |
| R37 | An 8-digit balance is shown in full (not truncated) in the wallet pill | pass | 12,347,368.50 truncated=false |
| R33 | No horizontal overflow on any captured screen | pass |  |
| R34 | No uncaught JS errors | pass |  |

## Accessibility (axe-core, WCAG 2.0/2.1 A + AA)

| Screen | Violations |
|---|---|
| home | 0 |
| tournaments | 0 |
| tournament-live | 0 |
| leaderboard | 0 |
| profile | 0 |
| wallet | 0 |

`before-redesign/` keeps seven captures from the pre-redesign app as evidence of the defects the redesign fixed (see its `index.json`).

