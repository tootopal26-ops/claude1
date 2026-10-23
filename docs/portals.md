# Admin and Staff portals

Mini Max has two web portals served by one Laravel app. They have separate
URLs, separate sign-in pages and their own identity, and the server never lets
an account cross from one to the other.

| | Admin portal | Staff portal |
|---|---|---|
| Sign in | `/admin/login` | `/staff/login` |
| Dashboard | `/admin/home` | `/staff/home` |
| Pages | `/admin/…` | `/staff/…` (same page set) |
| Route names | `admin.*` | `staff.*` |
| Who | accounts with `is_super_admin = true` | every other staff account |
| Sign-in page title | Admin sign in · Mini Max Control Center | Staff sign in · Mini Max Operations Hub |

Both portals read and write the same `staff_users` table and use the same
`staff` session guard. What differs is which accounts each portal accepts.

## How the boundary works

1. **Sign-in is portal-specific** (`App\Livewire\Concerns\SignsInToPortal`).
   The Admin page only ever opens a session for a super-admin account and the
   Staff page only for a non-super-admin one. A staff account typed into the
   Admin page — or the reverse — fails exactly like a wrong password.
2. **Every authenticated page runs `portal:admin` or `portal:staff`**
   (`App\Http\Middleware\EnsurePortalAccess`, registered as the `portal`
   alias) ahead of the per-page `permission:` check. A signed-in account that
   addresses the other portal gets a 403 page that points it back to its own
   dashboard, and the attempt is recorded in the audit log as
   `portal.access_denied`. The middleware is also registered as persistent for
   Livewire, so it is re-applied to `/livewire/update` calls from a page that
   is already open.
3. **Permissions are unchanged.** Both portals serve the same permission-gated
   pages; a staff member can open exactly what their preset allows, now under
   `/staff/…`. Hiding a navigation link is never the control.
4. **Links stay inside the portal.** Views and redirects use
   `portal_route('tournaments.index')` / `App\Support\Portal`, which resolve to
   the signed-in account's portal.

Consequence for existing accounts: a non-super-admin who used to sign in at
`/admin/login` now signs in at `/staff/login`. Nothing about the account
changes. Both login pages carry a quiet link to the other portal for exactly
this case.

## Sign-in protections

- One generic failure message for unknown username, wrong password,
  deactivated account and wrong portal; a password hash is always checked so
  response time doesn't separate those cases either.
- Throttling per username + IP (5 failures / 5 minutes) and per IP (30 / 15
  minutes) per portal, applied before credentials are looked at.
- Session ID is regenerated on sign-in; logout invalidates the session and
  rotates the CSRF token (`POST /admin/logout`, `POST /staff/logout`).
- After sign-in a user returns to the page they originally asked for only if
  it is a same-host path inside their own portal (`Portal::safeIntendedUrl`) —
  other hosts, other portals, protocol-relative or backslash tricks, and the
  login/logout endpoints are ignored.
- Failed attempts and denials are audit-logged (`staff.login_failed`,
  `portal.login_denied`, `staff.login`). Only usernames that exist are
  recorded, because a mistyped username field can contain a password.
- There is no "remember me": a session restored from a remember cookie lacks the
  security-version stamp checked by `EnsureStaffSecurityVersionIsCurrent` and
  would be signed straight out, so the login no longer issues the cookie. There
  is no password-recovery flow either, so the pages don't offer one.

## Hosting, DNS and SSL

**Path-based (what is configured).** Both portals live on the existing domain,
so nothing new is needed: no DNS record, no extra certificate, no extra vhost.
`SESSION_SECURE_COOKIE=true` (production) and `APP_URL` are the only
settings that matter, as in `deployment.md`.

Because both portals share the host, they also share the session cookie: one
person signed in to one portal in a browser profile can't be signed in to the
other at the same time. Use separate browser profiles to compare them.

**Subdomains (`admin.example.com` / `staff.example.com`) — not configured.**
The deployment domain isn't in the repository, so DNS, SSL and hosting for
subdomains could not be verified and the app still serves both portals from
paths. If you want subdomains later:

1. Add `A`/`AAAA` records for both names pointing at the server.
2. Issue a certificate that covers both hosts (a SAN certificate or a wildcard)
   and add both names to the CyberPanel/OpenLiteSpeed vhost (or two vhosts that
   share `backend/public`).
3. Decide on sessions: leave `SESSION_DOMAIN=null` (each host keeps its own
   cookie — the safest default) rather than `.example.com`, which would share
   one session cookie between the portals.
4. Constrain the route groups with `->domain(...)` in `routes/admin.php`, keep
   the `portal:` middleware (it is the real boundary), and point `APP_URL` at
   the primary host; Livewire's asset and update URLs are relative, so no
   other change is needed.

## Local QA accounts

The seeded `admin` account (super-admin) signs in at `/admin/login`. Create a
staff account in the Admin portal (Staff → New staff account) and sign in with
it at `/staff/login`.
