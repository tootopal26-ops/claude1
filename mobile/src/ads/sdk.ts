import type { ReactElement } from 'react';

import type { AdEvent, RewardedShowResult } from './types';

/**
 * The ad SDK seam. This file is the fallback used wherever LevelPlay can't run (the web
 * preview, Expo Go, tests): every call reports "unavailable", so the app behaves exactly as
 * it would on a device where no ad could be loaded. The real implementation is
 * sdk.native.ts, picked automatically for Android and iOS builds.
 */

export type AdSdkInitOptions = {
  appKey: string;
  /** Personalised ads only with the player's consent; otherwise non-personalised. */
  consent: boolean;
  testMode: boolean;
  onEvent: (event: AdEvent) => void;
};

export type RewardedShowOptions = {
  adUnitId: string;
  placement: string;
  /** The server's claim ID; LevelPlay echoes it in the server-to-server reward callback. */
  dynamicUserId: string;
  onEvent: (event: AdEvent) => void;
};

export type InterstitialShowOptions = {
  adUnitId: string;
  placement: string;
  onEvent: (event: AdEvent) => void;
};

export type InterstitialShowResult = 'shown' | 'failed' | 'unavailable';

export type BannerAdProps = {
  adUnitId: string;
  placement: string;
  onEvent: (event: AdEvent) => void;
  /** Called once an ad fills the slot, so the parent can show its "Ad" label. */
  onFilled?: () => void;
};

export type NativeAdProps = {
  placement: string;
  size: 'small' | 'medium';
  onEvent: (event: AdEvent) => void;
  onFilled?: () => void;
};

export function adSdkAvailable(): boolean {
  return false;
}

export async function initAdSdk(_options: AdSdkInitOptions): Promise<boolean> {
  return false;
}

export async function showRewardedAd(_options: RewardedShowOptions): Promise<RewardedShowResult> {
  return 'unavailable';
}

export async function showInterstitialAd(_options: InterstitialShowOptions): Promise<InterstitialShowResult> {
  return 'unavailable';
}

export function BannerAdView(_props: BannerAdProps): ReactElement | null {
  return null;
}

export function NativeAdView(_props: NativeAdProps): ReactElement | null {
  return null;
}
