# Admin & Staff panels — Day / Night mode

Date: 2026-10-28 · Branch: `claude/lucid-volta-ispwb4`

## What was built

- **Toggle** in the header of both panels (`x-ui.mode-toggle`): sun by day, moon by night, a turn-and-fade
  swap, tooltip and accessible name "Switch to Light Mode" / "Switch to Dark Mode", Enter/Space work, no reload.
  The **Appearance** menu also offers Light / Dark / System default. Both sign-in pages have the toggle too.
- **Palettes** (`resources/css/themes.css`, last section): Day — canvas `#F8FAFC`, white sidebar/cards/menus,
  text `#17111F` / `#475569` / `#5A6779`, borders `#E2E8F0`, purple `#6D28D9`. Night (default theme) — `#17111F`
  canvas, `#1E1729` sidebar, `#251D32` cards, `#302440` elevated, `#F8FAFC` / `#B6AFC4` text, `#3B304B` borders,
  `#6D28D9` purple. Success/warning/error use the given hues; small text uses AA-safe shades of the same hues
  (the given `#F04452` is 4.35:1 on the night card, `#20D58A`/`#F5C451` are under 2:1 on white).
  The six other color themes keep their own night surfaces and get the day surfaces with their own accent.
- **One central layer**: every page already draws only from design tokens, so the mode switches by
  `data-mode` on `<html>`; no page was restyled individually. Cards get a restrained shadow by day,
  menus/dialogs turn white, native controls (date pickers, selects, checkboxes) follow `color-scheme`.
- **Persistence**: per account **and per portal** in `staff_users.theme_modes` (`PUT /{portal}/appearance/mode`,
  no permission needed, behind the existing portal boundary). The server renders the saved mode onto `<html>`, so
  there is no flash; "System default" is resolved by the inline boot script before paint and follows the device
  live. New accounts start in Night. Guest sign-in/error pages use the last mode used in that portal on that
  device (`localStorage` `mm_theme_mode:admin|staff` — a non-sensitive UI setting). Other open tabs of the same
  portal follow a switch.

Not changed: authentication, permissions, routes other than the new save endpoint, financial values, API
responses, business logic, the Android app's tokens.

## Verification actually run

| Check | Result |
|---|---|
| `php artisan test --compact` (full suite) | 641 passed |
| `ThemeModeTest` (save/restore per account and portal, system, validation, portal boundary, guests, sign-in and error pages, saving never bumps the session security version) | 10 passed |
| `AdminUiDesignSystemTest` (every theme × both modes: WCAG AA for text on surfaces, glass, selected tints, semantic text on its tint; Android token parity) | 12 passed |
| `vendor/bin/pint --dirty` | passed |
| `npm run build` (Vite production build) | passed |
| Browser sweep, Chromium (Playwright) against the local server — `results.json` | 48/48 checks passed |
| Pages rendered and scanned with axe-core (WCAG 2 A/AA) | 96 renders (45 Admin + 3 Staff pages, each in Day and Night): 0 violations |
| "Dark slab" scan of every Day page (large dark backgrounds left over) | 0 found |

The sweep covered: Admin and Staff each switched Light → Dark → Light without reload; reload keeps the choice
(and the server-rendered HTML carries it); keyboard activation; visible focus ring; every sidebar page plus
create/detail pages in both modes; Appearance and profile menus; all four toast types; the confirmation dialog
(opened and cancelled — nothing was confirmed); the session-expired dialog; a form with selects and a date picker;
System default following the emulated device setting live and after reload; two tabs staying in step; phone
(390 px) pages and the navigation drawer with no horizontal scroll; tablet (820 px); sign-in pages; a Staff 403
page; Staff permissions unchanged (only the preset's sections in the menu, other pages still 403, the Admin
portal still refused). One finding was fixed during the sweep: night hint text on a selected option card was
4.41:1; the selected tint was deepened and the contrast test now covers that pairing.

Screenshots: `qa-report/day-night/*.webp` (Chromium, desktop 1440, tablet 820, phone-width 390).

## Remaining notes

- The Android app does not use this layer (it was not part of this request).
- The theme swatches in the Appearance menu preview each color theme's accent; they are not separate
  day/night previews.
