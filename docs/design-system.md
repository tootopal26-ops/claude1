# Mini Max Admin & Staff — Design System and Page Inventory

This document is the single reference for the Admin/Staff web UI redesign:
the page inventory, the design tokens, the component library, and the rules
every page follows. Android tokens live in `mobile/src/theme/tokens.ts` and map
to the same names (see "Token mapping").

## 1. Architecture facts that shape the redesign

- **One page set, two portals.** Admin (`/admin/*`, route names `admin.*`) and
  Staff (`/staff/*`, `staff.*`) are registered from the same list in
  `routes/admin.php`, share one `staff_users` table, one `staff` guard and one
  set of Livewire components, but the `portal` middleware keeps each account in
  its own portal: `is_super_admin` accounts belong to Admin, every other
  account to Staff (see `docs/portals.md`). Views and redirects use
  `portal_route('tournaments.index')` so a page always links inside the portal
  the user is in.
- Every route except `home`, `login` and `logout` (in either portal) carries a
  `permission:<key>` middleware. **UI hiding is cosmetic; the middleware stays
  the authority** and is not touched by the redesign.
- The shell therefore adapts **by role**: a super-admin sees the "Admin Console"
  identity and the full grouped navigation; any other account sees the "Staff
  Portal" identity, a workspace-style dashboard built from their permissions,
  and only the navigation groups/items they are authorized for.

## 2. Page inventory (route → component → view → permission)

| Route name | URL | Livewire component | View | Permission |
|---|---|---|---|---|
| `admin.login` | `/admin/login` | `Login` | `livewire/admin/login` | guest only |
| `admin.logout` | `POST /admin/logout` | closure | — | auth |
| `admin.home` | `/admin/home` | `Home` | `livewire/admin/home` | auth only |
| `admin.tournaments.index` | `/admin/tournaments` | `TournamentList` | `tournament-list` | `tournaments.view` |
| `admin.tournaments.create` | `/admin/tournaments/create` | `TournamentCreate` | `tournament-create` | `tournaments.manage` |
| `admin.tournaments.entries` | `/admin/tournaments/{t}/entries` | `TournamentEntries` | `tournament-entries` | `tournaments.view` |
| `admin.tournaments.results` | `/admin/tournaments/{t}/results` | `TournamentResults` | `tournament-results` | `results.submit` |
| `admin.templates.index` | `/admin/templates` | `TemplateList` | `template-list` | `recurring_tournaments.manage` |
| `admin.templates.create` | `/admin/templates/create` | `TemplateForm` | `template-form` | `recurring_tournaments.manage` |
| `admin.templates.edit` | `/admin/templates/{t}/edit` | `TemplateForm` | `template-form` | `recurring_tournaments.manage` |
| `admin.users.index` | `/admin/users` | `UserList` | `user-list` | `users.view` |
| `admin.users.show` | `/admin/users/{user}` | `UserShow` | `user-show` | `users.view` |
| `admin.deposits.index` | `/admin/deposits` | `DepositList` | `deposit-list` | `payments.view` |
| `admin.payment-settings` | `/admin/payment-settings` | `PaymentSettings` | `payment-settings` | `payments.manage` |
| `admin.withdrawals.index` | `/admin/withdrawals` | `WithdrawalList` | `withdrawal-list` | `withdrawals.manage` |
| `admin.withdrawals.show` | `/admin/withdrawals/{w}` | `WithdrawalShow` | `withdrawal-show` | `withdrawals.manage` |
| `admin.leaderboards.index` | `/admin/leaderboards` | `LeaderboardViewer` | `leaderboard-viewer` | `leaderboards.manage` |
| `admin.staff.index` | `/admin/staff` | `StaffList` | `staff-list` | `staff.manage` |
| `admin.staff.create` | `/admin/staff/create` | `StaffForm` | `staff-form` | `staff.manage` |
| `admin.staff.edit` | `/admin/staff/{s}/edit` | `StaffForm` | `staff-form` | `staff.manage` |
| `admin.presets.index` | `/admin/presets` | `PresetList` | `preset-list` | `staff.manage` |
| `admin.presets.create` | `/admin/presets/create` | `PresetForm` | `preset-form` | `staff.manage` |
| `admin.presets.edit` | `/admin/presets/{p}/edit` | `PresetForm` | `preset-form` | `staff.manage` |
| `admin.staff-salary.rules.index` | `/admin/staff-salary/rules` | `StaffSalaryRuleList` | `staff-salary-rule-list` | `salary.manage` |
| `admin.staff-salary.rules.create` | `/admin/staff-salary/rules/create` | `StaffSalaryRuleForm` | `staff-salary-rule-form` | `salary.manage` |
| `admin.staff-salary.rules.edit` | `/admin/staff-salary/rules/{r}/edit` | `StaffSalaryRuleForm` | `staff-salary-rule-form` | `salary.manage` |
| `admin.staff-salary.settlements.index` | `/admin/staff-salary/settlements` | `StaffSalarySettlementList` | `staff-salary-settlement-list` | `salary.view` |
| `admin.staff-salary.settlements.show` | `/admin/staff-salary/settlements/{s}` | `StaffSalarySettlementShow` | `staff-salary-settlement-show` | `salary.view` |
| `admin.notifications.index` | `/admin/notifications` | `NotificationHistory` | `notification-history` | `notifications.send` |
| `admin.notifications.create` | `/admin/notifications/create` | `NotificationCompose` | `notification-compose` | `notifications.send` |
| `admin.storage.index` | `/admin/storage` | `StorageDashboard` | `storage-dashboard` | `storage.view` |
| `admin.storage-assets.download` | `/admin/storage-assets/{a}/download` | controller | — (file response) | auth |
| `admin.audit-logs.index` | `/admin/audit-logs` | `AuditLogViewer` | `audit-log-viewer` | `audit_logs.view` |
| `admin.risk-alerts.index` | `/admin/risk-alerts` | `RiskAlertList` | `risk-alert-list` | `audit_logs.view` |
| `admin.legal.index` | `/admin/legal` | `LegalDocumentList` | `legal-document-list` | `legal.manage` |
| `admin.legal.create` | `/admin/legal/create` | `LegalDocumentForm` | `legal-document-form` | `legal.manage` |
| `admin.settings.index` | `/admin/settings` | `PlatformSettingsEditor` | `platform-settings-editor` | `settings.manage` |

All views are under `resources/views/livewire/admin/`. Routes that are in the
table but were not reachable from the old top navigation (payment settings,
salary rules, presets as a distinct item, risk alerts) are now reachable from
the sidebar.

## 3. Design tokens

Source of truth: `backend/resources/css/themes.css`. Tokens are CSS custom
properties (`--mm-*`) switched at runtime by `data-theme` / `data-font` on
`<html>`; `backend/resources/css/app.css` bridges them to Tailwind utilities
with `@theme inline`, so `bg-surface`, `text-fg-2`, `border-line`, `bg-brand/15`
… follow the active theme. **Pages never use raw palette colors or hex values**
(`AdminUiDesignSystemTest` fails the build if they do).

### Surface hierarchy (darkest → lightest) — Purple Glassmorphism defaults

| Utility | Token | Value | Use |
|---|---|---|---|
| `bg-app` | `--mm-bg` | `#0D0A12` | page canvas |
| `bg-sidebar` | `--mm-sidebar` | `#120D19` | navigation rail |
| `bg-surface` | `--mm-surface` | `#19131F` | cards, panels, tables |
| `bg-surface-2` | `--mm-surface-2` | `#21192B` | inputs, table headers, hover rows |
| `bg-surface-3` | `--mm-surface-3` | `#291F35` | menus, dialogs, popovers |
| `border-line` / `border-line-strong` | `--mm-border` / `--mm-border-strong` | `#382A46` / `#4D3A61` | dividers / input borders |

### Text

`text-fg` `#F6F1FB` (primary) · `text-fg-2` `#C2B5D0` (secondary) ·
`text-fg-3` `#9788A6` (muted/metadata) · `text-brand-text` `#B987FB` (links and
active labels on dark surfaces; the saturated brand is too dark for small text).

### Brand (purple is for actions, selection, focus and links — never a background wash)

`bg-brand` `#8B35F6` · `bg-brand-hover` `#A855F7` · `bg-brand-pressed` `#7025D8` ·
`bg-brand-soft` `#241536` (selected/active tint) · `text-on-brand` (text on a
brand button) · focus ring `--mm-ring`.

### Semantic colors (identical in every theme)

By night `success #20D58A` · `warning #F5C451` · `danger #F87C86` · `info #74B1FB`,
each with a `-soft` tint for badges/alerts (`bg-success-soft text-success`), plus
`bg-danger-solid text-on-danger` (`#D92D3F`) for destructive buttons. The tints
are made from the brand error/success/warning hues (`#F04452`, `#20D58A`,
`#F5C451`); the error text is a lighter tint of `#F04452` because the base red
is only 4.35:1 on a night card. By day the text colours deepen to
`#05704C` / `#835500` / `#BE1A2E` / `#1A58C4` so they stay AA on white and on
their own tints.

### Day / Night mode (Admin and Staff panels)

A second layer, independent of the color theme, switched by `data-mode="light|dark"`
on `<html>` (the last section of `themes.css`):

| | Night (`dark`) | Day (`light`) |
|---|---|---|
| canvas `--mm-bg` | `#17111F` | `#F8FAFC` |
| sidebar | `#1E1729` | `#FFFFFF` |
| cards `--mm-surface` | `#251D32` | `#FFFFFF` |
| inputs, table headers `--mm-surface-2` | `#2B2239` | `#F1F5F9` |
| elevated `--mm-surface-3` | `#302440` | `#EAEFF5` (badges, tracks) |
| menus/dialogs `--mm-float` | `#302440` | `#FFFFFF` |
| text `fg` / `fg-2` / `fg-3` | `#F8FAFC` / `#B6AFC4` / `#9A90AC` | `#17111F` / `#475569` / `#5A6779` |
| borders | `#3B304B` / `#4E4063` | `#E2E8F0` / `#CBD5E1` |
| brand / pressed / soft / text | `#6D28D9` / `#5B21B6` / `#33204D` / `#C4B0FA` | `#6D28D9` / `#5B21B6` / `#EDE9FE` / `#6D28D9` |

The night column is the default Purple Glassmorphism theme; the other six color
themes keep their own tinted night surfaces. By day every theme shares the
neutral column and keeps its own accent, with `--mm-brand-text` and
`--mm-brand-soft` deepened/lightened per theme for AA on white. Cards get a
restrained drop shadow by day (`--mm-shadow-card`, `shadow-card`) and a faint
top highlight by night; native controls follow `color-scheme`. The Android app
does not use this layer.

**Choosing and saving.** The header has a sun/moon switch (`x-ui.mode-toggle`:
sun by day, moon by night, labelled "Switch to Light Mode" / "Switch to Dark
Mode", works with Enter/Space) and the **Appearance** menu offers Light / Dark /
System default. The choice is saved per account **and per portal** on
`staff_users.theme_modes` (`PUT {portal}/appearance/mode`, no permission needed,
behind the same portal boundary) and rendered by the server onto `<html>`
(`data-mode`, `data-mode-pref`), so a page never flashes the wrong mode; "System
default" is resolved by the inline boot script before paint and follows the
device live. New accounts start in Night. Sign-in and error pages for guests use
the last mode used in that portal on the device (`localStorage`
`mm_theme_mode:admin` / `mm_theme_mode:staff`, a non-sensitive UI setting) and
have their own switch. Other tabs of the same portal follow a switch; colours
ease across for 0.3s unless the visitor asks for reduced motion. Tests:
`ThemeModeTest`, and `AdminUiDesignSystemTest` checks AA for every theme in
both modes.

### Themes and fonts

Seven color themes (`config('minimax.theme_colors')`) and seven font themes
(`theme_fonts`) are all supported. Every theme defines the full token set with
neutral-dark surfaces tinted toward its hue; the brand accent changes per theme.
Where white text on the brand color would fail contrast (emerald, blue, gold,
steel) the theme sets a dark `--mm-on-brand`. `AdminUiDesignSystemTest` asserts
each theme defines every token and meets WCAG AA (4.5:1) for its core pairings.
Selection is made in the header **Appearance** menu and persisted in
`localStorage` (`mm_theme_color`, `mm_theme_font`); an inline boot script
(`components/theme-boot`) applies it before first paint. Glass (below) works
the same in every theme.

### Glass

One restrained recipe, driven by tokens in `themes.css` so every theme and both
portals share it, and mirrored in the Android app:

| Token | Value | Used by |
|---|---|---|
| `--mm-glass-opacity` | 88% | `.mm-panel` — cards, form sections, tables: a translucent surface with a hairline border and a faint top highlight; **no blur** (static panels stay cheap to paint) |
| `--mm-glass-float-opacity` | 94% | `.mm-float` (menus, dialogs, toasts, tooltips) and `.mm-glass-bar` (the header, the sidebar/drawer, sticky action bars) — these overlap content, so they also blur it |
| `--mm-glass-filter` | `blur(16px) saturate(1.15)` | the blur above |
| `--mm-glass-hero-opacity` | 78% | `.mm-glass` — the sign-in panels over their artwork |
| `--mm-glow-strength` / `-2` | 14% / 8% | `.mm-ambient` — the quiet brand-tinted radial glow behind the workspace, the only thing the glass has to show |

`.mm-glass-bar` puts its colour and blur on a `::before`, because a blurred
element becomes the backdrop root for its descendants and would stop the menus
inside the header from seeing the page behind them.

**Fallbacks.** Where the browser cannot blur (`@supports not (backdrop-filter)`),
or the visitor asks for reduced transparency or more contrast, every opacity
becomes 100%, the filter `none` and the glow 0 — solid surfaces, never a
half-transparent one over unpredictable content. `forced-colors` uses the system
canvas with a `CanvasText` border and no glow. Browsers without `color-mix()`
get no glow at all.

**Contrast.** `AdminUiDesignSystemTest` checks WCAG AA for text on each glass
surface in the worst case behind it (both glows stacked behind a panel; the
solid brand colour behind a blurred layer) for every theme, which is how the
muted text colour of five themes was found to be slightly too dim on menus and
dialogs and lightened. A rendered check measures the pixels actually behind
7,385 text samples across 7 themes × 6 pages × (page, menu open): lowest 4.83:1.

### Typography, spacing, shape

Inter-first sans stack (user's selected font theme overrides it). Body 14px /
22px. Page title `text-xl sm:text-2xl font-semibold tracking-tight`; section
titles `text-sm font-semibold`; table headers 12px uppercase `text-fg-3`.
Controls are 36px high (`h-9`; `h-8` small, `h-11` large); cards are `rounded-xl`
with `p-5`; controls `rounded-lg`. Page content is centered in `max-w-[90rem]`.

### Token mapping to Android

`mobile/src/theme/tokens.ts` carries the same names and values (see that file);
the Android app is dark-only on the neutral surface hierarchy with purple used
the same restrained way. `mobile/src/theme/glass.ts` is the Android side of the
glass recipe (`glassPanel`, `glassFloat`, `glassScrim`; `AmbientGlow` is the
glow): cards are translucent over the glow with a hairline border, and since
there is no backdrop blur on Android in this build, dialogs use the solid
fallback. A test keeps its numbers equal to the CSS tokens and rejects
hand-rolled `${color}e6` alpha suffixes in screens and components.

## 4. Application shell

`components/layouts/admin.blade.php` (authenticated pages) and
`components/layouts/auth.blade.php` (login). Navigation is defined once in
`App\View\AdminNavigation` (grouped, permission-filtered, with a route-pattern
for the active state) and read by the sidebar, the dashboard and the header
context line. Behavior (Alpine, in `resources/js/app.js`): collapsible sidebar
with tooltips in the collapsed rail (state persisted), off-canvas drawer below
`lg`, appearance menu, account menu with secure POST logout, toast host,
global confirm dialog and a session-expired dialog (HTTP 419 on Livewire
requests). Error pages (`errors/403|404|419|500|503`) use `<x-error-page>`.

## 5. Component library (`resources/views/components/ui`, used as `<x-ui.*>`)

| Component | Purpose / key props |
|---|---|
| `button` | `variant` primary\|secondary\|outline\|ghost\|danger\|danger-ghost, `size` sm\|md\|lg, `href` (renders `<a>`), `icon`, `icon-only` (give it `aria-label`), `loading="methodName"` (spinner + disables while that Livewire call runs — prevents double submits), `confirm="…" confirm-title confirm-label` (custom confirm dialog) |
| `input` `select` `textarea` | `label`, `hint`, `required`, `optional`, `wrapperClass`; id, `aria-invalid`, `aria-describedby` and the validation message are derived from the `wire:model` name automatically |
| `checkbox` `radio` `switch` | `label`, `hint`; same auto error handling |
| `field` | low-level label/hint/error wrapper for custom controls (`id`, `label`, `hint`, `error`) |
| `form-section` | titled group of fields: heading column + card, two-column grid inside |
| `card` | `title`, `description`, `padding`, `actions` slot |
| `page-header` | `title`, `description`, `breadcrumbs=[['label'=>…,'url'=>…],…]`, `actions` slot |
| `table` / `table-empty` | `head` slot, `footer` slot (pagination), `label` (accessible name of the scroll region); styling from `.mm-table` |
| `badge` | `status="pending"` maps every status string to a semantic tone, or `variant` |
| `stat` | KPI card: `label`, `value`, `hint`, `icon`, `tone`, `href`, `coin` (shows the coin asset before the value) |
| `alert` | inline `variant` info\|success\|warning\|danger, `title` |
| `flash` | turns `session('status')` into a toast — put one at the top of every page that flashes |
| `empty-state` | icon + title + description + action slot |
| `tabs` / `tab` | segmented control / tabs (`active`, `href` or `wire:click`) |
| `kv` | label/value pair for `<dl>` detail lists |
| `dropdown` | anchored popover with `trigger` slot (not for use inside scrolling containers) |
| `modal` | dialog sheet opened with `$dispatch('open-modal','name')` |
| `skeleton` `tooltip` | loading placeholder / accessible tooltip |

Icons: `<x-icon name="…" :size="16" />` (see `app/View/Components/Icon.php`).
Coins: always `<x-coin-asset :size="16" />` next to the number — never `₹`.

## 6. Page conventions (how to convert or add a page)

1. Single root `<div>` (Livewire). Start with `<x-ui.flash />` if the component
   flashes `status`; drop inline `session('status')` banners.
2. `<x-ui.page-header>` for the title (+ breadcrumbs on create/edit/detail
   pages, + primary action in the `actions` slot). Show an action only when the
   account holds its permission (`auth('staff')->user()->hasPermission(...)`);
   the route middleware stays the authority.
3. Lists: `<x-ui.table>` with `head`, `<x-ui.badge :status>` for statuses,
   `<x-ui.table-empty>` for no data, `{{ $paginator->links() }}` in the
   `footer` slot, long text `max-w-* truncate` + `title`.
4. Forms: `x-ui.input|select|textarea|checkbox|switch` keeping the existing
   `wire:model` names exactly; group long forms with `x-ui.form-section`; submit
   with `<x-ui.button type="submit" loading="save">`; `novalidate` on the form;
   keep server-side validation as the only validation.
5. Consequential actions: `<x-ui.button confirm="…">` (never `wire:confirm`).
6. Only theme tokens — no `violet-*`, `emerald-*`, `text-white`, `bg-black/30`,
   hex colors, or interpolated class fragments (Tailwind can't see them).
7. Responsive: grids collapse to one column; tables scroll inside their wrapper;
   nothing essential is hidden on small screens.
8. No fabricated data. Business logic, validation and wire:model/method names
   are never changed by a visual pass.

## 7. Guard tests

`AdminUiDesignSystemTest` (tokens only, no `wire:confirm`, full token set per
theme, WCAG contrast per theme and on every glass surface, glass fallbacks present,
no ad-hoc `backdrop-blur` in views, Android glass constants equal the web tokens), `AdminNavigationTest` (nav ↔ routes ↔
permissions stay in sync, role-filtered), `AdminPagesRenderTest` (every
parameter-free admin page renders in the shell; branded error pages).

## 8. Verification and known limits

The redesign was verified in a real browser against the running app: every
admin route at 1920/1440/820/390 px (no console errors, no page-level
horizontal overflow, no axe wcag2a/2aa/21a/21aa violations), all seven themes
and seven fonts on dense pages, keyboard and reduced-motion behaviour, the
shell/confirm-dialog/session-expiry interactions, and an authorization matrix
for restricted accounts. The evidence and results are indexed in
`qa-report/REDESIGN_REPORT.md` and `qa-report/redesign/`.

Known limits: the Android changes are verified by type-checking, the currency
lint and the token-parity test only (no emulator is available in the build
environment); native checkboxes inside large labels are counted as "small
targets" by the sweep script because the label row is the real touch target.
