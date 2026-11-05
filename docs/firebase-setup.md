# Firebase push notifications — setup

## 1. Firebase project — NOT yet created (action required)

**Correction:** an earlier pass of this documentation stated a Firebase project
(`mini-max-89c03`) had already been created, and left matching-looking
`google-services.json` / `service-account.json` files on disk. That was false — no Firebase
project has ever been created for this app from this environment (there is no tool here that
can create one), and those two files contained fabricated values (a syntactically valid but
fake API key and RSA private key). They have been replaced with files containing obvious
`REPLACE_WITH_YOUR_...` placeholders so a build or deploy fails loudly and clearly instead of
silently depending on fake credentials. Neither file was ever committed to git (both are
gitignored), so no fake secret ever reached the repository.

**What you need to do, for real, before push notifications can work:**
1. Go to the [Firebase console](https://console.firebase.google.com/), create a project (any
   name), then add an Android app inside it with package name exactly `com.minimax.esports`
   (must match `mobile/app.json`'s `android.package` — a mismatch fails silently: tokens
   register, pushes never arrive, with no error anywhere).
2. Download that Android app's real `google-services.json` and replace
   `mobile/google-services.json` with it.
3. In the same project, Project settings → Service accounts → "Generate new private key" →
   download the JSON → replace `backend/storage/app/firebase/service-account.json` with it.
4. Keep both files local-only, exactly as the table in §2 describes — never commit them.

## 2. Where the two credential files live

Neither file is committed to git. Both are required for push notifications to work at all.

| File | Purpose | Local path | Git status |
|---|---|---|---|
| Admin SDK service account JSON | Backend sends pushes via the Firebase Admin SDK (`kreait/laravel-firebase`) | `backend/storage/app/firebase/service-account.json` | Ignored (`storage/app/.gitignore`) |
| `google-services.json` | Android client registers with FCM | `mobile/google-services.json`, referenced by `mobile/app.json`'s `android.googleServicesFile` | Ignored (`mobile/.gitignore`) |

**Getting a fresh copy of either file**, if needed: Firebase Console → Project settings →
- Service accounts tab → "Generate new private key" for the Admin SDK JSON.
- General tab → your Android app → "google-services.json" download button.

**Supplying them in environments other than this one:**
- Local dev: drop the files at the paths above.
- EAS Build (mobile): upload `google-services.json` as an EAS secret file and reference it in
  `eas.json`'s build profile (`"env": {"GOOGLE_SERVICES_JSON": "..."}` + an `app.config.js` that
  reads it), or use `eas credentials` to manage it per-profile. Do not commit it to get a build
  working — that's a secrets-into-git shortcut this project explicitly avoids.
- Production VPS (backend): copy `service-account.json` to the same relative path via your deploy
  process (scp/rsync as part of release, not git), keep file mode `600`.

## 3. Backend environment variables

```
# backend/.env
FIREBASE_CREDENTIALS=   # leave empty to use the default path above, or set an absolute path
QUEUE_CONNECTION=database   # already the project default — see §5, this must be a real worker in prod
```

`config/firebase.php`'s `credentials` key defaults to
`storage_path('app/firebase/service-account.json')` when `FIREBASE_CREDENTIALS` is unset, so local
dev needs no `.env` change as long as the file is at that path.

## 4. Mobile: this requires an EAS development or production build — not Expo Go

`expo-notifications`' `getDevicePushTokenAsync()` returns a **raw FCM token** tied to this
project's `google-services.json`. Expo Go only ships Expo's own shared Firebase project, so it
cannot produce a token this backend can send to — you'll get either a failure or a token that
silently never receives anything. Concretely:

```bash
cd mobile
npx eas-cli@latest build --profile development --platform android   # or --profile production
```

then install that build on a device/emulator and run `npx expo start --dev-client` against it.
`app.json` already has the `expo-notifications` config plugin and `android.googleServicesFile`
wired up — `eas build` picks up `google-services.json` from the path configured there (supplied
per environment as described in §2).

**Not yet verified**: there is no physical Android device or emulator available in this build
environment, so the token-registration → push-received round trip has been verified only at the
backend/API layer (migrations, controllers, the queued job against a mocked `Messaging` client —
see `backend/tests/Feature/Notifications/`). The first real EAS build should be smoke-tested by
hand: log in, confirm `device_push_tokens` gets a row, join a tournament, confirm a push arrives
and tapping it opens the right tournament screen.

## 5. Queue worker (required — pushes are sent via a queued job)

`App\Jobs\SendPushNotificationJob` is queued, not sent inline, so **nothing is pushed unless a
queue worker is running**. In this sandboxed dev environment nothing runs the queue automatically;
start one manually when testing:

```bash
cd backend
php artisan queue:work --tries=3
```

### Production VPS (per the deployment phase in the project plan)

Run it under Supervisor so it restarts on crash and on deploy:

```ini
; /etc/supervisor/conf.d/minimax-queue.conf
[program:minimax-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/path/to/backend
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/backend/storage/logs/queue-worker.log
stopwaitsecs=3600
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start minimax-queue:*
```

After any deploy that changes job/model code, run `php artisan queue:restart` so workers pick up
the new code (they otherwise keep running the old code in memory).

## 6. Quick end-to-end check once a device build exists

1. `php artisan queue:work` running.
2. Install the EAS dev/production build, log in as a test player, accept the push permission
   prompt.
3. Confirm a row appeared: `php artisan tinker --execute 'App\Models\DevicePushToken::latest()->first()'`.
4. Join the seeded free tournament from the app.
5. A push notification should arrive ("You're in!"); confirm `notification_batches.push_sent_count`
   incremented and `push_delivery_logs` has a `sent` row for that device.
6. Tap the push (or the bell icon in-app) — it should open that tournament's screen.
