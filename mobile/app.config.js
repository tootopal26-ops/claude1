/**
 * Dynamic config wrapping the static app.json, so the production API domain can be
 * supplied at EAS build time (via eas.json's per-profile `env` block) instead of being
 * permanently baked into the repo. Expo merges app.json's `expo` object into `config`
 * before calling this function — everything not explicitly overridden below (package
 * name, signing-relevant fields, icons, plugins, etc.) comes through unchanged.
 *
 * Local dev (`expo start`, `expo export`) and any build profile that sets no
 * API_BASE_URL keep the existing http://localhost:8000/api/v1 fallback — nothing
 * changes for the current workflow unless a profile opts in.
 *
 * google-services.json (Firebase / FCM) is never committed (see docs/firebase-setup.md),
 * so it cannot be assumed to exist where the build runs. It is resolved in this order:
 *   1. the GOOGLE_SERVICES_JSON env var — on EAS Build this is an EAS "file" environment
 *      variable / secret file, whose value is the path of the uploaded file;
 *   2. the file app.json's android.googleServicesFile points at, if it exists on disk.
 * If neither is available the setting is dropped so the build still succeeds, but the
 * resulting app has no Firebase configuration: it installs and runs, while
 * getDevicePushTokenAsync() fails (already caught in src/notifications/pushRegistration.ts)
 * and the device receives no push notifications until a real file is supplied.
 */
const fs = require('fs');
const path = require('path');

function resolveGoogleServicesFile(config) {
  if (process.env.GOOGLE_SERVICES_JSON) {
    return process.env.GOOGLE_SERVICES_JSON;
  }
  const configured = config.android && config.android.googleServicesFile;
  if (configured && fs.existsSync(path.resolve(__dirname, configured))) {
    return configured;
  }
  return null;
}

module.exports = ({ config }) => {
  const { googleServicesFile: _declared, ...androidWithoutGoogleServices } = config.android || {};
  const googleServicesFile = resolveGoogleServicesFile(config);

  if (!googleServicesFile) {
    console.warn(
      '[app.config] google-services.json not found and GOOGLE_SERVICES_JSON is unset: ' +
        'building WITHOUT Firebase config — push notifications will not work in this build.',
    );
  }

  return {
    ...config,
    android: {
      ...androidWithoutGoogleServices,
      ...(googleServicesFile ? { googleServicesFile } : {}),
    },
    extra: {
      ...config.extra,
      apiBaseUrl: process.env.API_BASE_URL || config.extra.apiBaseUrl,
    },
  };
};
