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

## Building

```bash
# Local testing build (EAS-managed credentials, fine to regenerate):
npx eas-cli@latest build --profile development --platform android

# Internal/team testing build:
npx eas-cli@latest build --profile preview --platform android

# The real, production-signed release:
npx eas-cli@latest build --profile production --platform android
```

Each profile builds an `.apk` (not an `.aab` — `.aab` is a Play
Store–only format you can't sideload) with `distribution: "internal"`,
which gives you a direct install link/QR code from the EAS build
dashboard once it finishes.

`appVersionSource: "local"` (set in `eas.json`) means `app.json`'s own
`version` field is the source of truth for the app's version string, and
`autoIncrement: true` on the `production` profile bumps Android's
internal `versionCode` automatically on each production build — bump
`app.json`'s `version` by hand for each user-visible release.

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
