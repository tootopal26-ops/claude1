# Mobile app: building and releasing

The app is privately distributed — installed directly from a downloaded
APK, not through the Play Store — so there's no store review step and no
store-forced update mechanism. That makes the signing key the one thing
that must never be lost: Android refuses to install an update unless
it's signed with the exact same certificate as the version already on
the device, so losing the key doesn't just block new builds, it strands
every existing install (the only way back is uninstall-and-reinstall,
which loses whatever Mini Max doesn't itself store server-side).

This is why `mobile/eas.json`'s `production` profile uses
`"credentialsSource": "local"` — a keystore you generate and hold
yourself — rather than letting EAS generate and hold one for you.
EAS-managed credentials are fine for `development`/`preview` builds
(disposable, used for testing, fine to lose), which is why only
`production` sets this.

## One-time setup

1. **Create (or use an existing) Expo account**, then from `mobile/`:
   ```bash
   npx eas-cli@latest login
   npx eas-cli@latest init
   ```
   `eas init` links this project to your Expo account and writes an
   `extra.eas.projectId` into `app.json` — this is account-bound and
   can't be fabricated or shared across accounts, which is why it isn't
   already in `app.json`.

2. **Generate the production Android keystore**, on a machine you
   control, not in a CI runner or a shared/ephemeral environment:
   ```bash
   keytool -genkeypair -v -keystore minimax-release.keystore \
     -alias minimax -keyalg RSA -keysize 2048 -validity 10000
   ```
   You'll be prompted for a keystore password and a key password —
   generate strong ones and store all three (the file, the keystore
   password, the key password) in a password manager or secrets vault.
   There is no recovery if any of these are lost.

3. **Create `mobile/credentials.json`** pointing at it (already
   gitignored — see `mobile/.gitignore`):
   ```json
   {
     "android": {
       "keystore": {
         "keystorePath": "minimax-release.keystore",
         "keystorePassword": "<keystore password>",
         "keyAlias": "minimax",
         "keyPassword": "<key password>"
       }
     }
   }
   ```
   Keep `minimax-release.keystore` itself outside the repo entirely (or
   gitignored alongside `credentials.json` — `.gitignore` already
   excludes `*.jks`/`*.p12`/`*.key`, but this keystore's extension is
   whatever you chose above; rename it to end in `.jks` or adjust
   `.gitignore` to match if you don't). Back up both files somewhere
   that survives this machine being lost.

### Backing up the keystore (do this before the first production build)

Because `credentialsSource: "local"` means EAS never holds a copy of
this key, there is nothing to recover from Expo's side if it's lost —
unlike an EAS-managed keystore, which Expo can re-issue credentials
for. Back it up yourself, immediately after generating it:

```bash
# From mobile/, with the keystore and credentials.json already created:
tar -czf minimax-signing-backup-$(date +%Y%m%d).tar.gz \
  minimax-release.keystore credentials.json

# Encrypt the archive before it leaves this machine (you'll be asked
# for a passphrase — store that passphrase itself in a password
# manager, separately from the archive):
gpg --symmetric --cipher-algo AES256 \
  minimax-signing-backup-$(date +%Y%m%d).tar.gz
```

Copy the resulting `.tar.gz.gpg` file to at least two places that
don't depend on this machine or this VPS (e.g. a password manager's
encrypted file storage and a separate cloud drive) and keep the
`keytool` command above (with the alias, keystore/key passwords, and
validity you used) written down alongside it — if the archive is ever
restored, you need those same values to use the keystore with `eas
build`. Never run `keytool -genkeypair` again for this app once a
production build has shipped: a second keystore cannot update
installs signed with the first one.

## Building

```bash
# Local testing build (EAS-managed credentials, fine to regenerate):
npx eas-cli@latest build --profile development --platform android

# Internal/team testing build:
npx eas-cli@latest build --profile preview --platform android

# The real, production-signed release — sideloadable .apk:
npx eas-cli@latest build --profile production --platform android

# Same production signing + version, packaged as a Play Store .aab
# instead — only needed if you decide to publish through Google Play
# (see "Play Store .aab" below). Nobody can sideload an .aab.
npx eas-cli@latest build --profile production-aab --platform android
```

`production` and `development`/`preview` build an `.apk` with
`distribution: "internal"`, which gives you a direct install link/QR
code from the EAS build dashboard once it finishes — this is the
profile to use for the current private-distribution model (a build
shared directly with players, not through any app store).

`appVersionSource: "local"` (set in `eas.json`) means `app.json`'s own
`version` field is the source of truth for the app's version string.
`autoIncrement: true` on `production`/`production-aab` bumps Android's
internal `versionCode` automatically on each build made with that
profile — EAS reads the current `android.versionCode` from the app
config (none is set explicitly today, so the first production build
starts it at `1`) and writes the incremented value back into
`app.json` after the build, so the next production build picks up from
there. Bump `app.json`'s `version` (the user-visible version string,
e.g. `1.0.0` → `1.0.1`) by hand for each release — `autoIncrement` only
ever touches `versionCode`, never `version`.

### Play Store `.aab` — an optional profile, not the current distribution model

`production-aab` exists only so an `.aab` can be produced later if you
decide to publish on Google Play — it is not part of the current
workflow and changes nothing about how `production` builds today. The
two profiles share the exact same signing credentials and version, so
switching between them (or doing both from the same release) is safe;
only the packaging format differs (`apk` vs. `app-bundle`). Publishing
on Google Play additionally requires a Google Play Console developer
account ($25 one-time), its own listing/review process, and app
signing managed through Play App Signing — none of that is set up
here and is a separate decision from anything in this repo.

### Production API domain

The app reads its API base URL from `Constants.expoConfig.extra.apiBaseUrl`
(`mobile/src/api/client.ts`), which `mobile/app.config.js` now computes
from the `API_BASE_URL` build-time environment variable (falling back
to `http://localhost:8000/api/v1` for local dev, unchanged). `eas.json`
sets a placeholder on the `production`/`production-aab` profiles only:

```json
"env": { "API_BASE_URL": "https://REPLACE_WITH_PRODUCTION_DOMAIN/api/v1" }
```

**Before the first production build**, edit that placeholder in
`mobile/eas.json` to the real domain the backend is deployed to (the
same `APP_URL` from the server's `.env` — see `deployment.md`), with
the `/api/v1` suffix kept. `development`/`preview` are untouched and
keep talking to `localhost` for local testing against a backend
running on the same machine/emulator host.

## Android permissions

`app.json` declares no explicit `android.permissions` array — every
permission in the built app comes implicitly from a plugin:
`expo-notifications` (notification posting; `POST_NOTIFICATIONS` on
Android 13+, requested at runtime, not install time) and the LevelPlay
ads SDK's own manifest merges (network state, advertising ID — see
`docs/ads-monetization.md`). There is nothing excessive or unused to
trim here; if a future dependency adds a permission you don't expect,
`npx expo config --type public` (or inspecting the generated
`android/app/src/main/AndroidManifest.xml` after `expo prebuild`, which
this sandbox cannot run) will show the final merged list.

## Distributing

However you point testers/players at the resulting `.apk` — the EAS
build dashboard's direct link/QR, or hosting the file on your own site
for people to download — Android will refuse to install it until the
user enables "Install unknown apps" for whichever app they're
downloading through (browser, file manager); this is expected for any
non-Play-Store Android app and is worth a line in your own onboarding
instructions to players, not something this repo can configure away.

## iOS — an open decision, not yet built

Android's sideloading model has no real iOS equivalent. Apple's options
for getting a build onto a device without the App Store are each a
real trade-off, and none of them match "share an APK link":

- **TestFlight** — still goes through a (lighter) App Store review,
  caps at 10,000 external testers, and builds expire after 90 days.
- **Ad-hoc distribution** (standard $99/year Apple Developer Program) —
  no review, but every device's UDID must be individually registered
  with Apple first, capped at 100 devices per device type per year —
  workable for an internal team, not for a general player base.
- **Apple Developer Enterprise Program** ($299/year, requires Apple's
  approval of the organization) — the only option that genuinely matches
  Android's "anyone can install the file," but Apple restricts ADEP to
  genuine internal/employee distribution; using it for a public
  consumer app violates its terms and risks the whole account being
  revoked.

None of these is implemented here because which one to use is a real
product/business decision (and for ADEP, an eligibility one) that
belongs to whoever operates Mini Max, not something to default
silently. `eas.json`'s `development`/`preview` profiles will build for
iOS once an Apple Developer account and the relevant credentials exist
(`eas build --platform ios`), which is enough for internal team testing
on registered devices in the meantime — the open question is only about
the production distribution channel for everyone else.
