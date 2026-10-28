/**
 * Expo config plugin for Unity LevelPlay (unity-levelplay-mediation), which ships without
 * one. Applied at `expo prebuild` / EAS Build time; it changes nothing at runtime.
 *
 * Android:
 *  - adds the Play Services libraries the LevelPlay React Native README lists as required
 *    (app set ID, advertising ID, basement);
 *  - adds the Unity Ads adapter (brings com.unity3d.ads:unity-ads);
 *  - adds the AdMob adapter and writes com.google.android.gms.ads.APPLICATION_ID to the
 *    manifest ONLY when an AdMob App ID is provided. Google's SDK stops the app at launch if
 *    it is present without an App ID, so without one AdMob demand is simply left out.
 *
 * The AdMob App ID is read from the build environment (LEVELPLAY_ADMOB_APP_ID), never from
 * the repository. For a development build, LEVELPLAY_USE_ADMOB_TEST_APP_ID=1 uses Google's
 * public sample App ID instead (test ads only).
 *
 * Adapter versions are pinned below; check them against LevelPlay's adapter compatibility
 * table for the mediation SDK version the plugin pins (9.6.1 for plugin 9.3.0) before a
 * release build — see docs/ads-monetization.md.
 */
const { withAppBuildGradle, withAndroidManifest, AndroidConfig } = require('expo/config-plugins');

const DEFAULTS = {
  unityAdsAdapterVersion: '5.15.0',
  admobAdapterVersion: '5.19.0',
  playServicesAppSetVersion: '16.0.2',
  playServicesAdsIdentifierVersion: '18.0.1',
  playServicesBasementVersion: '18.3.0',
};

// Google's documented sample AdMob App ID: serves test ads only.
const ADMOB_TEST_APP_ID = 'ca-app-pub-3940256099942544~3347511713';
const MARKER = '// unity-levelplay-mediation (plugins/withLevelPlay.js)';

function resolveAdmobAppId(options) {
  if (options.admobAppId) return options.admobAppId;
  if (process.env.LEVELPLAY_ADMOB_APP_ID) return process.env.LEVELPLAY_ADMOB_APP_ID;
  if (process.env.LEVELPLAY_USE_ADMOB_TEST_APP_ID === '1') return ADMOB_TEST_APP_ID;
  return null;
}

function withLevelPlayGradle(config, options, admobAppId) {
  return withAppBuildGradle(config, (mod) => {
    if (mod.modResults.contents.includes(MARKER)) return mod;

    const lines = [
      `    ${MARKER}`,
      `    implementation 'com.google.android.gms:play-services-appset:${options.playServicesAppSetVersion}'`,
      `    implementation 'com.google.android.gms:play-services-ads-identifier:${options.playServicesAdsIdentifierVersion}'`,
      `    implementation 'com.google.android.gms:play-services-basement:${options.playServicesBasementVersion}'`,
      `    implementation 'com.unity3d.ads-mediation:unityads-adapter:${options.unityAdsAdapterVersion}'`,
    ];
    if (admobAppId) {
      lines.push(`    implementation 'com.unity3d.ads-mediation:admob-adapter:${options.admobAdapterVersion}'`);
    }

    mod.modResults.contents = mod.modResults.contents.replace(/dependencies\s*\{/, (match) => `${match}\n${lines.join('\n')}\n`);
    return mod;
  });
}

function withLevelPlayManifest(config, admobAppId) {
  return withAndroidManifest(config, (mod) => {
    const application = AndroidConfig.Manifest.getMainApplicationOrThrow(mod.modResults);
    if (admobAppId) {
      AndroidConfig.Manifest.addMetaDataItemToMainApplication(application, 'com.google.android.gms.ads.APPLICATION_ID', admobAppId);
    } else {
      AndroidConfig.Manifest.removeMetaDataItemFromMainApplication(application, 'com.google.android.gms.ads.APPLICATION_ID');
    }
    return mod;
  });
}

module.exports = function withLevelPlay(config, userOptions = {}) {
  const options = { ...DEFAULTS, ...userOptions };
  const admobAppId = resolveAdmobAppId(options);

  if (admobAppId && !/^ca-app-pub-\d{16}~\d{10}$/.test(admobAppId)) {
    throw new Error(`withLevelPlay: "${admobAppId}" is not an AdMob App ID (expected ca-app-pub-XXXXXXXXXXXXXXXX~XXXXXXXXXX).`);
  }

  config = withLevelPlayGradle(config, options, admobAppId);
  config = withLevelPlayManifest(config, admobAppId);
  return config;
};
