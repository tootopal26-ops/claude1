import { createElement, useEffect, useMemo, useRef, type ReactElement } from 'react';
import { TurboModuleRegistry } from 'react-native';

import type {
  AdSdkInitOptions,
  BannerAdProps,
  InterstitialShowOptions,
  InterstitialShowResult,
  NativeAdProps,
  RewardedShowOptions,
} from './sdk';
import type { AdEvent, RewardedShowResult } from './types';

export type {
  AdSdkInitOptions,
  BannerAdProps,
  InterstitialShowOptions,
  InterstitialShowResult,
  NativeAdProps,
  RewardedShowOptions,
} from './sdk';

/**
 * Unity LevelPlay (with the AdMob and Unity Ads adapters) on Android and iOS.
 *
 * The package is required lazily and only when its native module is in the binary: in Expo
 * Go, or in a build made before the plugin was added, importing it would throw at start-up.
 * Without it every call reports "unavailable", exactly like the web fallback (sdk.ts).
 *
 * Nothing here decides a reward. A rewarded ad's result only tells the app whether to start
 * waiting for the server, which credits rewards solely from LevelPlay's signed
 * server-to-server callback carrying the claim's dynamic user ID.
 */

type LevelPlayModule = typeof import('unity-levelplay-mediation');
type BannerMethods = import('unity-levelplay-mediation').LevelPlayBannerAdViewMethods;

const LOAD_TIMEOUT_MS = 30_000;
/** onAdRewarded and onAdClosed can arrive in either order; wait this long after a close. */
const REWARD_AFTER_CLOSE_GRACE_MS = 1_500;

let cached: LevelPlayModule | null | undefined;
let initialized = false;
let initPromise: Promise<boolean> | null = null;

function levelPlay(): LevelPlayModule | null {
  if (cached !== undefined) {
    return cached;
  }

  try {
    cached = TurboModuleRegistry.get('LevelPlayMediation') ? (require('unity-levelplay-mediation') as LevelPlayModule) : null;
  } catch {
    cached = null;
  }

  return cached;
}

function now() {
  return new Date().toISOString();
}

type AnyAdInfo = { adNetwork?: string | null; revenue?: number | null; precision?: string | null; adUnitId?: string | null };

function eventFrom(placement: string, event: AdEvent['event'], info?: AnyAdInfo | null, extra?: Partial<AdEvent>): AdEvent {
  return {
    placement,
    event,
    ad_network: info?.adNetwork ?? null,
    ad_unit_id: info?.adUnitId ?? null,
    // Client-reported impression revenue is analytics only; nobody is ever paid from it.
    revenue_usd: typeof info?.revenue === 'number' && Number.isFinite(info.revenue) ? info.revenue : null,
    precision: info?.precision ?? null,
    occurred_at: now(),
    ...extra,
  };
}

export function adSdkAvailable(): boolean {
  return levelPlay() !== null;
}

export async function initAdSdk({ appKey, consent, testMode, onEvent }: AdSdkInitOptions): Promise<boolean> {
  const sdk = levelPlay();
  if (!sdk) {
    return false;
  }

  if (initialized) {
    await sdk.LevelPlayPrivacySettings.setGDPRConsent(consent).catch(() => undefined);
    return true;
  }

  initPromise ??= (async () => {
    try {
      // Consent and privacy flags must be set before init to apply to the first requests.
      await sdk.LevelPlayPrivacySettings.setGDPRConsent(consent);
      await sdk.LevelPlayPrivacySettings.setCCPA(!consent);
      // Test ads themselves come from the dashboards (LevelPlay test devices, AdMob test
      // devices / test App ID, Unity Ads test mode); the app only turns on adapter logging.
      await sdk.LevelPlay.setAdaptersDebug(testMode);

      const ok = await new Promise<boolean>((resolve) => {
        sdk.LevelPlay.init(sdk.LevelPlayInitRequest.builder(appKey).build(), {
          onInitSuccess: () => resolve(true),
          onInitFailed: (error) => {
            onEvent({ placement: 'sdk', event: 'init_failed', error_code: String(error.errorCode), occurred_at: now() });
            resolve(false);
          },
        }).catch(() => resolve(false));
      });

      if (ok) {
        initialized = true;
        onEvent({ placement: 'sdk', event: 'init_success', occurred_at: now() });
      }

      return ok;
    } catch {
      return false;
    } finally {
      initPromise = null;
    }
  })();

  return initPromise;
}

/** Loads a full-screen ad, shows it, and settles when it closes or fails. */
async function runFullScreen(
  kind: 'rewarded' | 'interstitial',
  adUnitId: string,
  placement: string,
  onEvent: (event: AdEvent) => void
): Promise<'rewarded' | 'closed' | 'failed' | 'no_fill'> {
  const sdk = levelPlay();
  if (!sdk || !initialized) {
    return 'failed';
  }

  const ad = kind === 'rewarded' ? new sdk.LevelPlayRewardedAd(adUnitId) : new sdk.LevelPlayInterstitialAd(adUnitId);

  return new Promise((resolve) => {
    let settled = false;
    let rewarded = false;
    let closedTimer: ReturnType<typeof setTimeout> | null = null;

    const timeout = setTimeout(() => finish('no_fill'), LOAD_TIMEOUT_MS);

    function finish(result: 'rewarded' | 'closed' | 'failed' | 'no_fill') {
      if (settled) {
        return;
      }
      settled = true;
      clearTimeout(timeout);
      if (closedTimer) {
        clearTimeout(closedTimer);
      }
      void ad.remove().catch(() => undefined);
      resolve(result);
    }

    const listener = {
      onAdLoaded: (info: AnyAdInfo) => {
        clearTimeout(timeout);
        onEvent(eventFrom(placement, 'loaded', info));
        ad.showAd(placement).catch(() => finish('failed'));
      },
      onAdLoadFailed: (error: { errorCode: number }) => {
        onEvent(eventFrom(placement, 'no_fill', null, { ad_unit_id: adUnitId, error_code: String(error.errorCode) }));
        finish('no_fill');
      },
      onAdDisplayed: (info: AnyAdInfo) => {
        onEvent(eventFrom(placement, 'impression', info));
      },
      onAdDisplayFailed: (error: { errorCode: number }, info: AnyAdInfo) => {
        onEvent(eventFrom(placement, 'display_failed', info, { error_code: String(error.errorCode) }));
        finish('failed');
      },
      onAdClicked: (info: AnyAdInfo) => {
        onEvent(eventFrom(placement, 'clicked', info));
      },
      onAdClosed: (info: AnyAdInfo) => {
        onEvent(eventFrom(placement, 'closed', info));
        if (kind === 'interstitial' || rewarded) {
          finish(rewarded ? 'rewarded' : 'closed');
          return;
        }
        closedTimer = setTimeout(() => finish(rewarded ? 'rewarded' : 'closed'), REWARD_AFTER_CLOSE_GRACE_MS);
      },
      onAdRewarded: (_reward: unknown, info: AnyAdInfo) => {
        rewarded = true;
        onEvent(eventFrom(placement, 'rewarded_client', info));
        if (closedTimer) {
          finish('rewarded');
        }
      },
    };

    // The two ad classes take the same listener shape (the interstitial one has no onAdRewarded).
    (ad as { setListener(listener: unknown): void }).setListener(listener);
    onEvent(eventFrom(placement, 'requested', null, { ad_unit_id: adUnitId }));
    ad.loadAd().catch(() => finish('failed'));
  });
}

export async function showRewardedAd({ adUnitId, placement, dynamicUserId, onEvent }: RewardedShowOptions): Promise<RewardedShowResult> {
  const sdk = levelPlay();
  if (!sdk || !initialized) {
    return 'unavailable';
  }

  try {
    // Must be set before the ad loads: it is what the reward callback carries back.
    await sdk.LevelPlay.setDynamicUserId(dynamicUserId);
  } catch {
    return 'failed';
  }

  const result = await runFullScreen('rewarded', adUnitId, placement, onEvent);

  return result === 'no_fill' ? 'unavailable' : result;
}

export async function showInterstitialAd({ adUnitId, placement, onEvent }: InterstitialShowOptions): Promise<InterstitialShowResult> {
  if (!levelPlay() || !initialized) {
    return 'unavailable';
  }

  const result = await runFullScreen('interstitial', adUnitId, placement, onEvent);

  return result === 'closed' ? 'shown' : result === 'no_fill' ? 'unavailable' : 'failed';
}

export function BannerAdView({ adUnitId, placement, onEvent, onFilled }: BannerAdProps): ReactElement | null {
  const sdk = levelPlay();
  const ref = useRef<BannerMethods | null>(null);
  const handlers = useRef({ onEvent, onFilled });
  handlers.current = { onEvent, onFilled };

  useEffect(() => {
    if (!sdk || !initialized) {
      return;
    }
    handlers.current.onEvent(eventFrom(placement, 'requested', null, { ad_unit_id: adUnitId }));
    ref.current?.loadAd();
    return () => ref.current?.destroy();
  }, [sdk, adUnitId, placement]);

  const listener = useMemo(
    () => ({
      onAdLoaded: (info: AnyAdInfo) => {
        handlers.current.onEvent(eventFrom(placement, 'loaded', info));
        handlers.current.onFilled?.();
      },
      onAdLoadFailed: (error: { errorCode: number }) =>
        handlers.current.onEvent(eventFrom(placement, 'no_fill', null, { ad_unit_id: adUnitId, error_code: String(error.errorCode) })),
      onAdDisplayed: (info: AnyAdInfo) => handlers.current.onEvent(eventFrom(placement, 'impression', info)),
      onAdClicked: (info: AnyAdInfo) => handlers.current.onEvent(eventFrom(placement, 'clicked', info)),
    }),
    [adUnitId, placement]
  );

  if (!sdk || !initialized) {
    return null;
  }

  return createElement(sdk.LevelPlayBannerAdView, {
    ref,
    adUnitId,
    adSize: sdk.LevelPlayAdSize.BANNER,
    placementName: placement,
    listener,
    style: { width: sdk.LevelPlayAdSize.BANNER.width, height: sdk.LevelPlayAdSize.BANNER.height, alignSelf: 'center' },
  });
}

export function NativeAdView({ placement, size, onEvent, onFilled }: NativeAdProps): ReactElement | null {
  const sdk = levelPlay();
  const handlers = useRef({ onEvent, onFilled });
  handlers.current = { onEvent, onFilled };

  const nativeAd = useMemo(() => {
    if (!sdk || !initialized) {
      return null;
    }

    return sdk.LevelPlayNativeAd.builder()
      .withPlacement(placement)
      .withListener({
        onAdLoaded: (_ad, info) => {
          handlers.current.onEvent(eventFrom(placement, 'loaded', info));
          handlers.current.onFilled?.();
        },
        onAdLoadFailed: (_ad, error) => handlers.current.onEvent(eventFrom(placement, 'no_fill', null, { error_code: String(error.errorCode) })),
        onAdClicked: (_ad, info) => handlers.current.onEvent(eventFrom(placement, 'clicked', info)),
        onAdImpression: (_ad, info) => handlers.current.onEvent(eventFrom(placement, 'impression', info)),
      })
      .build();
  }, [sdk, placement]);

  useEffect(() => {
    if (!nativeAd) {
      return;
    }
    handlers.current.onEvent(eventFrom(placement, 'requested'));
    nativeAd.loadAd();
    return () => nativeAd.destroyAd();
  }, [nativeAd, placement]);

  if (!sdk || !nativeAd) {
    return null;
  }

  return createElement(sdk.LevelPlayNativeAdView, {
    nativeAd,
    templateType: size === 'medium' ? sdk.LevelPlayTemplateType.Medium : sdk.LevelPlayTemplateType.Small,
    style: { width: '100%', height: size === 'medium' ? 330 : 110 },
  });
}
