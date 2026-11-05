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
 */
module.exports = ({ config }) => ({
  ...config,
  extra: {
    ...config.extra,
    apiBaseUrl: process.env.API_BASE_URL || config.extra.apiBaseUrl,
  },
});
