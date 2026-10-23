# Mini Max — Admin & Staff Redesign and Purple Design-System Restoration

Scope: the complete Admin/Staff web panel redesign and the global purple
design-system restoration across the web panel and the Android app.
Backend logic, database schema, API contracts, routes, permissions and
validation were not changed (see "What was deliberately not touched").

Companion documents: `docs/design-system.md` (tokens, shell, components, page
conventions, guard tests), `qa-report/redesign/` (screenshot evidence, indexed
below).

---

## 1. Result at a glance

| Area | Outcome |
|---|---|
| Application shell | Persistent left sidebar with six labeled groups, collapse/expand rail with tooltips, mobile drawer, compact header with breadcrumbs, profile menu with secure logout, Appearance menu (theme + font) |
| Auth and error screens | Branded login (with signed-out and session-expired notices), branded 403/404/419/500/503 pages, in-app session-expiry dialog for 419 |
| Dashboards | Real-data Admin dashboard and a role-aware Staff workspace — no fabricated metrics |
| Module pages | All 31 Livewire admin pages converted to the shared component library; the one controller route (evidence download) has no UI |
| Design system | One centralized token set (`resources/css/themes.css`), 7 colour themes + 7 font themes kept, Purple Glassmorphism as the restrained default, mirrored exactly in `mobile/src/theme/tokens.ts` |
| Android | Tokens, status/navigation bars, keyboard-area colour, shared Button/TextField/Notice/StatusChip/CoinAmount components, coin PNG preserved — **not visually verified** (see §8) |
| Automated tests | `php artisan test --compact`: **391 / 391 passed** (1,173 assertions); 26 new tests |
| Static checks | Pint passes; mobile `tsc --noEmit` clean; `check-no-raw-currency` clean (33 files); `npm run build` succeeds |
| Real-browser verification | 152 page loads (38 routes × 4 viewports) + 4 login loads + 49 theme loads + 98 font loads, authorization and interaction scripts — all clean on the final build (details in §6) |

---

## 2. Redesigned routes

### Admin Console (super-admin sees everything)

Every row below was loaded in a real browser at 1920, 1440, 820 and 390 px
wide. "Permission" is what the unchanged route middleware requires.

| Group | Route | Page | Permission |
|---|---|---|---|
| Overview | `/admin/home` | Dashboard | any signed-in staff (content adapts) |
| Tournament Operations | `/admin/tournaments` | Tournaments | `tournaments.view` |
| | `/admin/tournaments/create` | Create tournament | `tournaments.manage` |
| | `/admin/tournaments/{id}/entries` | Entries | `tournaments.view` |
| | `/admin/tournaments/{id}/results` | Results editor | `results.submit` |
| | `/admin/templates` | Recurring templates | `recurring_tournaments.manage` |
| | `/admin/templates/create`, `/{id}/edit` | Template form — sections *Basic Details / Tournament Configuration / Rewards / Recurrence*, no Booking Deadline field | `recurring_tournaments.manage` |
| Users & Finance | `/admin/users`, `/admin/users/{id}` | Players, player detail + wallet adjustment | `users.view` |
| | `/admin/deposits` | Deposits | `payments.view` |
| | `/admin/withdrawals`, `/admin/withdrawals/{id}` | Withdrawals, withdrawal workflow | `withdrawals.manage` |
| | `/admin/leaderboards` | Leaderboards | `leaderboards.manage` |
| | `/admin/payment-settings` | Payment settings | `payments.manage` |
| Team Management | `/admin/staff`, `/create`, `/{id}/edit` | Staff accounts | `staff.manage` |
| | `/admin/presets`, `/create`, `/{id}/edit` | Permission presets | `staff.manage` |
| | `/admin/staff-salary/settlements`, `/{id}` | Salary settlements | `salary.view` |
| | `/admin/staff-salary/rules`, `/create`, `/{id}/edit` | Salary rules | `salary.manage` |
| Communication | `/admin/notifications`, `/create` | Notification history, compose | `notifications.send` |
| System | `/admin/audit-logs` | Audit log | `audit_logs.view` |
| | `/admin/risk-alerts` | Risk alerts | `audit_logs.view` |
| | `/admin/storage` | Storage dashboard | `storage.view` |
| | `/admin/legal`, `/admin/legal/create` | Legal documents, publish | `legal.manage` |
| | `/admin/settings` | Platform settings | `settings.manage` |
| Auth | `/admin/login`, `POST /admin/logout` | Sign in / sign out | guest / staff |
| Errors | 403, 404, 419, 500, 503 | Branded error pages | — |

### Staff Portal

Admin and Staff share one codebase, one `staff` guard and the same
`permission:` middleware (this was already true before the redesign — there
are no separate `/staff/*` routes). The shell adapts to the account:

- The portal is labelled **Admin Console** for super-admins and **Staff Portal**
  for everyone else (sidebar brand, profile menu).
- The sidebar lists only the pages the account's permissions allow; groups with
  nothing visible disappear. Server-side `permission:` middleware remains the
  only authority — hiding a link never grants or removes access.
- The dashboard shows a staff workspace built from real queries only: KPI
  cards, the upcoming-tournaments table and "Needs attention" counts appear
  only for sections the account may open, activity is limited to the
  account's own actions unless it holds `audit_logs.view`, and a "Your
  sections" card links to everything the account can reach.
- An account with no permissions gets a clear "No sections available"
  empty state rather than a broken page; forbidden URLs render the branded 403
  page.

---

## 3. Components, tokens and shell changed

**Tokens** — `backend/resources/css/themes.css` (all seven themes, `--mm-*`
variables, selector `[data-theme='…']`) bridged to Tailwind utilities in
`backend/resources/css/app.css` (`@theme inline`): `bg-bg`, `bg-sidebar`,
`bg-surface{,-2,-3}`, `border-line`, `text-fg{,-2,-3}`, `bg-brand`,
`text-brand-text`, `ring-ring`, semantic success/warning/danger/info.
Purple Glassmorphism defaults: brand `#8B35F6` / hover `#A855F7` / pressed
`#7025D8` / soft `#241536`, app `#0D0A12`, sidebar `#120D19`, surfaces
`#19131F` / `#21192B` / `#291F35`, border `#382A46`, text `#F6F1FB` /
`#C2B5D0` / `#9788A6`. Purple is used for actions, active navigation, focus,
links and selected state only; surfaces are neutral dark.

**Shell** — `components/layouts/admin.blade.php`, `components/layouts/auth.blade.php`,
`components/theme-boot.blade.php` (applies theme/font/sidebar state before
first paint), `app/View/AdminNavigation.php` (nav catalogue, permission filter,
breadcrumbs/context, portal label), `resources/js/app.js` (Alpine `shell`,
`appearance`, `toasts`, `confirmDialog`, 419 handler).

**Component library** (`resources/views/components/ui`, `<x-ui.*>`): alert,
badge, button, card, checkbox, dropdown, empty-state, field, flash,
form-section, input, kv, modal, page-header, radio, select, skeleton, stat,
switch, tab, table, table-empty, tabs, textarea, tooltip; plus Livewire
pagination (`views/vendor/livewire/tailwind.blade.php`), `error-page`, and the
five `errors/*` pages.

**Android** — `mobile/src/theme/tokens.ts`, `ThemeContext.tsx`, `icons.tsx`,
`mobile/app.json`, new `Button`, `CoinAmount`, `Notice`, `StatusChip`,
`TextField` components, and every screen under `mobile/app/`.

A PHP test (`test_android_theme_tokens_match_the_web_tokens_exactly`) fails the
build if any Android token drifts from the web token for any theme.

---

## 4. Interaction and form quality

- One global confirm dialog (`<x-ui.button confirm="…">`) replaces every
  browser-native `wire:confirm`; a test bans the native one.
- Flash messages become toasts; a guard test makes sure any page that is a
  redirect target (or flashes to itself) renders the toast host.
- Forms keep their original `wire:model` names and server-side validation,
  show per-field errors with friendly attribute names, disable and spin the
  submit button while saving, and group long forms into sections.
- Tables scroll inside a focusable, labelled region; the tournament results
  editor stacks into cards on phones so the Prize column is reachable.
- Expired sessions: a plain navigation lands on login with an
  "your session expired" notice; a 419 on a Livewire request opens an in-app
  dialog instead of a dead page.
- Keyboard and a11y (each verified by script): login field autofocus and
  Enter-to-submit, a visible "Skip to content" link as the first tab stop that
  moves focus to `<main>`, a focus ring of at least 2px on every tab stop,
  Escape closing the Appearance menu (focus returned to its trigger) and the
  mobile drawer, the closed drawer being `inert`, dialogs using Alpine's focus
  trap (exercised through the confirm and session-expiry dialogs), one `<main>`/`<h1>` and a
  current-page marker per page, and `prefers-reduced-motion` collapsing
  transitions.

---

## 5. Files changed

61 tracked files modified plus new files (`git status`):

- **Web shell/CSS/JS (5)** — `themes.css`, `app.css`, `app.js`, `layouts/admin.blade.php`, `bootstrap/app.php`
- **New web infrastructure** — `AdminNavigation.php`, `layouts/auth.blade.php`, `theme-boot.blade.php`, `error-page.blade.php`, `errors/*` (5), `components/ui/*` (25), `vendor/livewire/tailwind.blade.php`
- **Livewire pages (31 views)** — every file in `resources/views/livewire/admin/`
- **Livewire classes (6)** — `Home` (real-data KPIs), `Login` (auth layout), `WithdrawalShow` (`resetValidation()`), `TemplateForm`, `TournamentCreate`, `NotificationCompose` (`validationAttributes()` only), plus `Icon.php` (extra icons) and `routes/admin.php` (logout status flash)
- **Tests (4 new files)** — `AdminUiDesignSystemTest`, `AdminNavigationTest`, `AdminPagesRenderTest`, `AdminDashboardTest`; `StaffLoginTest` adjusted for HTML-escaped assertions
- **Mobile (16 modified + 5 new components)** — theme, 10 screens, legal gate
- **Docs/evidence** — `docs/design-system.md`, this report, `qa-report/redesign/`

---

## 6. Verification performed (all in a real Chromium against the running app)

| Check | Scope | Result |
|---|---|---|
| Route × viewport sweep | 38 routes × 1920/1440/820/390 = 152 loads | 0 non-200, 0 console errors, 0 failed requests, 0 page-level horizontal overflow, 0 clipped elements |
| axe-core (wcag2a/2aa/21a/21aa) | all 152 loads + 4 login loads | 0 violations |
| Theme sweep | 7 themes × 7 dense pages (49 loads) | 0 errors, 0 overflow, 0 axe violations |
| Font sweep | 7 fonts × 14 pages (98 loads) | 0 errors, 0 overflow |
| Token contrast | every theme, 12 text/surface/brand pairings | all ≥ 4.5:1 (enforced by PHPUnit) |
| Authorization | 140 allow/deny checks across restricted accounts and routes | all correct; 403 page rendered for forbidden URLs |
| Shell interactions | collapse/persist/reload, tooltip, appearance menu, theme + font persistence across navigation, profile menu, logout notice, mobile drawer (open, Escape, close on navigate, inert while closed) | 25 / 25 |
| Confirm dialog | approve/reject/process/pay/fail withdrawal flow with dialog accept and cancel | 16 / 16 |
| Session expiry | redirect notice + mocked 419 dialog | 6 / 6 |
| Keyboard / reduced motion / skip link | Tab order, Escape, focus return, skip link | clean |

The 61 "tiny targets" the sweep counts per viewport are native checkboxes
inside large clickable labels (the whole label row is the touch target), not
standalone small controls.

---

## 7. Screenshot index (`qa-report/redesign/`)

All files are real Playwright captures of the final build (a full-page capture
with the viewport grown to the document height so the sticky sidebar renders
completely).

| Folder | Content |
|---|---|
| `desktop-1440/` | All 38 admin pages at 1440 px |
| `mobile-390/` | All 38 admin pages at 390 px |
| `tablet-820/` | Dashboard, tournaments, results editor, players, withdrawal detail, template form, staff form, settings, notification compose |
| `wide-1920/` | Dashboard, tournaments, audit log, storage, player detail |
| `staff-portal/` | A limited-permission staff account and a zero-permission account: dashboard, forbidden page, legal, notifications at 1440 and 390 |
| `themes/` | Dashboard, tournaments, player detail in each of the 7 themes |
| `before-after/` | 12 side-by-side composites: tournaments, tournament create, results, templates form, player detail, withdrawal detail, staff, preset edit, notification compose, audit log, storage, settings |
| `interactions/` | Profile menu, appearance menu, collapsed sidebar tooltip, mobile drawer, confirm dialog, approved toast, validation error, session-expired dialog and login notice, signed-out notice, skip link, theme+font applied |
| `error-pages/` | 404, 419, 500, 503 at 1440 and 390 |
| `login/` | Sign-in at 1920, 1440, 820, 390 |

The older `qa-report/screenshots/` folder (pre-redesign audit) is intentionally
left as the "before" reference.

---

## 8. Remaining issues, limits and untested items

**Not verified**

- **Android app — visual/on-device verification was not possible.** There is no
  emulator or device in this environment (ENV-001 from the earlier audit).
  The Android changes are verified by `tsc --noEmit`, the currency lint, the
  token-parity PHP test and code review only. System-bar colours, keyboard
  colour strips, and every mobile screen's real layout still need a look on a
  device before release.
- **Staff-portal evidence is two seeded roles** (a limited account and a
  zero-permission account) plus the 140-check authorization matrix, not a
  screenshot of every route under every permission combination.
- **Not part of the visual sweep:** `GET /admin` (302 to `/admin/home`),
  `POST /admin/logout` (exercised through the profile-menu flow), and
  `GET /admin/storage-assets/{id}/download` (a file response with no UI;
  covered by the existing PHPUnit storage tests).
- **Payments, withdrawals and notifications** were exercised only against the
  local test setup — no real gateway payout or mass notification was sent.

**Known gaps (pre-existing or out of scope, left unchanged on purpose)**

- Payment Settings has an "Active" switch that the deposit flow does not
  actually enforce (pre-existing behaviour). Fixing enforcement is a
  business-logic change, so it was left as-is and is flagged here for a decision.
- Features the product doesn't have are not faked: no global search, no staff
  notification inbox, no forgot-password / remember-me on staff login, no
  separate Games admin route.
- `axe` cannot compute colour contrast for gradient/blurred backgrounds, so
  contrast is additionally guaranteed by the per-theme token test rather than
  by axe alone.
- Test fixtures: a salary settlement (backdated derived events) and result
  evidence were created through the app's own forms to get realistic
  screenshots; the QA database is local and not part of the repo.

## 9. What was deliberately not touched

Backend domain logic (`app/Domain/*`), API controllers and resources,
migrations/schema, route paths and names, permission catalogue and middleware,
validation rules, wallet/withdrawal/payment/result state machines. Livewire
component changes are limited to presentation support (validation attribute
labels, resetting validation state, the dashboard's read-only queries, the
login layout, a logout status message). No new frontend framework or runtime
dependency was added; Alpine/Livewire/Tailwind/Vite were already in the stack.
