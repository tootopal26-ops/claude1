# Design system: four themes, three densities, an admin editor

Priority 2 of the master prompt. Backed entirely by `App\Support\Themes` (pure data + a CSS builder), `App\Services\AdminSettings`
(the admin read/save/reset/revert actions) and the dynamically generated `/theme.css` endpoint (`App\Controllers\SiteController::themeCss`,
unchanged in shape from the original build — only its content generation changed). Tested by `tests/Unit/ThemesTest.php` and
`tests/Integration/AppearanceTest.php`.

## 1. How a theme repaints the whole app with zero per-component changes

Every component in `resources/css/*.css` already reads **semantic CSS custom properties** — `--color-canvas`, `--color-ink`,
`--color-surface`, `--glass-bg`, `--accent-from`/`--accent-to`, `--radius-lg`, … — because Tailwind v4's `@theme` directive
compiles utility classes (`bg-canvas`, `text-ink`, `border-line`, `rounded-2xl`, …) to `var()` references, not literal colours.
`/theme.css` is loaded **after** the compiled bundle and does nothing but override those same custom properties on `:root` for
whichever theme/density/accent an admin has chosen (`App\Support\Themes::css()`). No component was rewritten to add theme
support; switching a theme only changes *values*, never *selectors*.

```php
Themes::css('obsidian', 'standard', '#A855F7', '#7E22CE');
// → ":root{color-scheme:dark;--color-canvas:#15131C;--color-ink:#F5F3FA;...--glass-bg:rgb(30 27 40 / 0.66);
//    --accent-from:#A855F7;--accent-to:#7E22CE;--ambient-1:...;--radius-lg:18px;...--glass-blur:18px;...}"
```

The success/destructive/warning/info palette (green buy buttons, red errors, amber warnings) is **deliberately not themed** —
it stays the same four colours across all four themes, per the master prompt's own rule ("keep green for positive primary
actions, red for errors … across all four themes").

## 2. The four themes (`App\Support\Themes::NAMES`)

| Name | Label | Scheme | Default accent | Character |
|---|---|---|---|---|
| `rose` (default) | Rose Glass | light | `#E83E91` → `#B91F70` | white & pale rose, pink/red accents — the original look |
| `aurora` | Aurora Prism | light | `#7C3AED` → `#5B21B6` | white backgrounds, restrained purple/blue/cyan highlights |
| `obsidian` | Obsidian Neon | dark | `#A855F7` → `#7E22CE` | deep charcoal, dark translucent glass, purple/magenta accents |
| `emerald` | Emerald Luxe | light | `#0F766E` → `#115E59` | warm white/grey, emerald primary actions, a financial-dashboard feel |

Each theme supplies its own `canvas`/`surface`/`ink`/`ink-soft`/`muted`/`faint`/`line`/`line-strong`/`brand-50…700`/`brand-red`
tokens and a 3-stop `ambient` gradient (the body's soft background glow). **Obsidian is the only theme that overrides the
glass-surface tokens** (`--glass-bg`, `--glass-bg-strong`, `--glass-border`, `--glass-border-strong`, `--glass-inset`,
`--glass-inset-strong`) — the other three rely on `.glass`/`.glass-strong`'s own light-mode fallback values, since a
translucent-white glass effect only needs special-casing once the canvas behind it goes dark.

`color-scheme` (`light`/`dark`) is set on `:root` so native form controls (scrollbars, `<select>` dropdowns, date pickers)
render correctly without extra CSS.

## 3. Density (`App\Support\Themes::DENSITIES`)

A second, independent knob — `compact` / `standard` (default) / `premium` — scales corner radii (`--radius-lg/xl/2xl`) and
glass blur (`--glass-blur`, `--glass-blur-strong`) within the master prompt's stated safe range (glass blur 12–30px). It does
not affect colour.

## 4. The accent colour (independent of theme)

`theme.accent_from`/`theme.accent_to` is the existing two-stop gradient used for primary buttons/highlights, carried over
unchanged from the original Website settings accent picker (now living under Appearance). Picking a **new theme** without
explicitly sending an accent falls back to that theme's own default pair (`Themes::defaultAccent()`); sending an accent
explicitly, or not changing theme, keeps exactly what was asked — never silently stuck on a previous theme's colours.
`App\Support\Color::buttonContrast($hex)` (OKLab colour-mix math, simulating white text on the accent mixed 86% toward black,
the same formula the button's real CSS uses) must read **≥ 4.5:1** or the save is rejected with a 422 naming the exact ratio —
this is enforced server-side (`AdminSettings::saveAppearance`) and mirrored client-side for live feedback as the admin types.

## 5. Admin → Appearance & Themes (`catalog.manage`)

Routes (`app/routes_admin.php`):

| Method | Path | Action |
|---|---|---|
| `GET` | `/admin/api/appearance` | current theme/density/accent + every theme's metadata + `can_revert`/`can_reset` flags |
| `PUT` | `/admin/api/appearance` | save a new theme/density/accent (validated, audited, idempotent) |
| `POST` | `/admin/api/appearance/reset` | restore the documented defaults (Rose Glass, standard, `#E83E91`/`#B91F70`) |
| `POST` | `/admin/api/appearance/revert` | swap back to whatever was live immediately before the last save |

The page (`resources/js/admin/appearance.js`) renders a card grid — one per theme, each with a live colour-swatch preview
dot-set drawn from `Themes::meta()['swatch']` — a density selector, and the accent picker with its live contrast readout. A
persistent toolbar (shown whenever `can_revert || can_reset`, **not** only while there are unsaved changes) carries **Revert**
and **Reset to defaults**; the ordinary **Save** button only appears once something is actually dirty. All three write
actions go through `BaseController::once()` (idempotency keys) and are recorded in the audit log under `settings.appearance`.

**Live preview is CSP-safe:** this site's CSP is `style-src 'self'` (no `unsafe-inline`), so the preview never writes an inline
`style="…"` attribute. Every preview colour is applied by JavaScript's `element.style.setProperty()` on a scoped preview
container at runtime — the same technique the existing Website accent picker already used — which the CSP allows because it
is a DOM API call, not an HTML attribute.

## 6. One level of undo (`theme.previous_json`)

Every successful save snapshots whatever was live *before* the change into `theme.previous_json` (a JSON blob: theme, density,
accent_from, accent_to — a settings value, not a new table). **Revert** re-applies that snapshot through the same
`saveAppearance()` path, which means reverting itself creates a new "previous" snapshot — reverting is symmetric and can
itself be undone. Calling revert with nothing to revert to returns `409 no_previous`.

## 7. Settings keys (no migration — see [DATABASE.md](DATABASE.md))

`theme.name`, `theme.density`, `theme.accent_from`, `theme.accent_to`, `theme.previous_json` are plain rows in the existing
generic `admin_settings` key/value store (`App\Services\Settings`) — adding them needed no migration, only new default keys,
written the first time Appearance is read or saved. `theme.accent_from`/`theme.accent_to`'s defaults changed from the
pre-Priority-2 placeholder (`#ff2d87`/`#ff3b3b`) to Rose Glass's own documented default (`#E83E91`/`#B91F70`); nothing in an
existing installation is rewritten by this change — a site that already saved its own accent keeps it.

## 8. Fonts

Headings (`--font-heading`) use a self-hosted **Manrope** variable font (OFL-licensed, `docs/FONT-LICENSE-Manrope.txt`), added
the same way the existing Inter body font is self-hosted: one `@fontsource-variable` npm package, one subsetted `woff2`
covering the full 200–800 weight range, declared in `resources/css/tokens.css`. The body font (Inter) is unchanged.

## 9. Two deliberate exceptions to "everything is theme-aware"

* **The payment QR frame** (`.qr-frame`) stays a literal white background regardless of theme — a UPI scanner app's ability to
  read the code must never depend on an admin's colour choice.
* **`.svc-premium`** (the dark-gold "premium" storefront card variant) keeps its own independent, fixed cosmetic styling — it
  is an unrelated visual variant that existed before the theme system and is not part of it.

## 10. Tests

`tests/Unit/ThemesTest.php` (7 tests / 68 assertions) exercises `Themes::` as pure functions: every theme/density name
validates correctly, `css()` emits the right custom properties for each theme (including the glass-* exception for Obsidian
only), `meta()`/`all()`/`defaultAccent()`/`colorScheme()` return the documented values.

`tests/Integration/AppearanceTest.php` (9 tests / 40 assertions) exercises the admin service end to end: a fresh install
defaults to Rose Glass/standard/the documented accent; saving a new theme switches the accent to that theme's own default and
is audited; an explicitly-sent accent survives a theme switch; an unknown theme/density or a too-light accent is rejected with
a 422 naming the field; revert restores the exact previous state and is itself reversible; revert with nothing to revert to
returns 409; reset restores the documented defaults regardless of current state.

Browser QA: the full storefront and admin regression suites (`site-badges`, `a11y`, `admin-catalog`, and the rest of
`tools/qa/`) were rerun against all four themes on both desktop and mobile viewports with no regressions.
