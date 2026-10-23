# Separate Admin & Staff portals — report

Design and operating notes live in [`docs/portals.md`](../docs/portals.md); this file records what was built and what was verified. All screenshots are real Chromium captures of the running app in `qa-report/portals/`.

## 1. Final URLs

| Portal | Sign in | Dashboard | Accepts |
|---|---|---|---|
| Admin | `/admin/login` | `/admin/home` | accounts with `is_super_admin = true` |
| Staff | `/staff/login` | `/staff/home` | every other staff account |

Path-based on the existing domain. Subdomains were **not** configured — the production domain is not in the repository, so DNS, SSL and hosting could not be verified (see §6).

## 2. Files and routes changed

- **Routes** — `routes/admin.php` now registers one page list twice, under `/admin` (`admin.*`) and `/staff` (`staff.*`), each with `login`, `logout` and a `portal:<name>` group. Every page route keeps its `permission:` middleware unchanged.
- **New** — `app/Support/Portal.php` (+ `helpers.php` → `portal_route()`), `app/Http/Middleware/EnsurePortalAccess.php`, `app/Livewire/Concerns/SignsInToPortal.php`, `app/Livewire/Staff/Login.php`, `resources/views/livewire/staff/login.blade.php`, `components/portal/{brand,password-field,form-alert,arena-rings,bracket-motif}.blade.php`, `docs/portals.md`.
- **Changed** — `Admin/Login.php` + its view (new composition), `components/layouts/auth.blade.php`, `bootstrap/app.php`, `AppServiceProvider.php`, `EnsureStaffSecurityVersionIsCurrent.php`, `AdminNavigation.php`, `Home.php`, the admin layout / error page, `app.css` (`.mm-glass`, portal surfaces, entrance animation), `themes.css` (danger hover), 30 Livewire views and 8 form classes (route names → `portal_route()` / `Portal::routeName()`), `composer.json` (autoload `files` entry for the helper — no new dependency).
- **Tests** — new `PortalSeparationTest` (30 cases incl. 16 redirect-safety cases); `StaffLoginTest` rewritten for the two portals; 46 existing route references in other tests moved to `staff.*` where the actor is a non-admin.

## 3. Authentication and middleware changes

- `EnsurePortalAccess` (`portal:admin|staff`) refuses an account that addresses the other portal with a 403 that points back to its own dashboard, audit-logs `portal.access_denied`, and also runs on Livewire updates.
- Sign-in is per portal: the Admin page only opens a session for a super-admin, the Staff page only for a non-super-admin. All failures — unknown user, wrong password, deactivated account, wrong portal — show one message and always cost one password hash.
- Throttle: 5 failures / 5 min per username+IP and 30 / 15 min per IP, per portal, checked before credentials.
- Guests are redirected to the login of the portal they asked for; a signed-in account opening a login page is sent to its own dashboard.
- Post-login redirect honours the originally requested URL only when it is a same-host path inside the user's own portal (`Portal::safeIntendedUrl`, 16 hostile inputs tested).
- Livewire persistent middleware now also re-applies the security-version check. **This closes a gap that predates this work:** a deactivated or re-permissioned staff account with a page already open could keep running Livewire actions until it reloaded. Verified live: bump the version, the next action lands on `/staff/login?expired=1`.
- "Remember me" is removed: the old login always issued a remember cookie, but a session restored from it had no security-version stamp and was signed straight out, so it never worked.
- Fixed a pre-existing accessibility defect found by the sweep: danger buttons hovered to `#ef4444` (3.76:1 with white text); they now darken to `#b91c1c` (6.47:1). Solid brand buttons likewise hover to the darker pressed shade instead of the lighter hover shade (3.96:1 in the default theme). A guard test covers both.

## 4. Screenshots (`qa-report/portals/`)

`admin-login-{desktop,tablet,mobile}.png`, `staff-login-{desktop,tablet,mobile}.png`, plus `admin-login-invalid-credentials`, `admin-login-loading-state`, `staff-login-invalid-credentials`, `staff-login-throttled`, `admin-opening-staff-url-403`, `staff-opening-admin-url-403`, `staff-dashboard-after-login`, `staff-livewire-search`.

## 5. Security tests and results

| Check | Result |
|---|---|
| `php artisan test --compact` | **434 / 434** passed |
| Live browser script (`portal-live`) — guest redirects, empty/invalid submit, loading + disabled state, generic error, wrong-portal credentials both ways, 6th-attempt throttle, password toggle by keyboard, tab order, Enter-to-submit, no remember cookie, admin→`/staff/*` 403, staff→`/admin/*` 403, login-page redirects for signed-in accounts, logout, Livewire round trips on both portals, reduced motion, axe | **60 / 60** |
| Route sweep, both portals × 1440/390 (38 pages each, full-permission staff account for `/staff`) | 152 loads: 0 non-200, 0 console errors, 0 failed requests, 0 overflow, 0 axe violations |
| Login pages, axe wcag2a/2aa/21a/21aa, desktop + mobile | 0 violations |
| Stale open page after account change | signed out on the next action |
| Laravel log during the live runs | no new errors |

## 6. Still required / not done

- **Existing staff accounts must now use `/staff/login`.** An `/admin/login` attempt by a non-super-admin fails with the generic message (both pages link to the other portal).
- **Username, not email.** `staff_users` has a `username` and no email column, so both pages ask for a Username. Switching to email needs a migration, data for existing accounts and changes to the staff form — say if you want it.
- **No remember-me and no forgot-password**, deliberately: neither exists securely today.
- **Subdomains** need DNS records, a certificate covering both hosts, vhost entries and `->domain()` constraints; the steps are in `docs/portals.md`. Nothing is needed for the path-based setup beyond the existing `SESSION_SECURE_COOKIE=true` and `APP_URL`.
- One browser profile holds one staff session, so an admin and a staff member can't be signed in side by side in the same profile (shared cookie).
- Not verified: production TLS/cookie behaviour (local HTTP only), and Android (unchanged in this task).
