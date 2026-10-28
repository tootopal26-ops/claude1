import * as SecureStore from 'expo-secure-store';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState, Text, View } from 'react-native';

import { ApiError } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { Button } from '../components/Button';
import { Sheet } from '../components/sheet/Sheet';
import { makeStyles } from '../theme/ThemeContext';
import { SPACE, TYPE } from '../theme/tokens';
import { useWallet } from '../wallet/WalletContext';
import { cancelRewardedClaim, fetchAdsConfig, fetchRewardedClaim, openRewardedClaim, sendAdEvents, simulateRewardedClaim } from './api';
import { adSdkAvailable, initAdSdk, showInterstitialAd, showRewardedAd } from './sdk';
import type { AdEvent, AdPlacementKey, AdsClientConfig, RewardedClaim, RewardedShowResult } from './types';

export type RewardedRequest =
  | { purpose: 'tournament_ticket'; tournamentId: number }
  | { purpose: 'daily_reward' }
  | { purpose: 'ads_earning' }
  | { purpose: 'campaign'; campaignId: number }
  /** The redeem endpoint opens this claim itself (it reserves the code first). */
  | { purpose: 'redeem_code'; claim: { token: string; dynamic_user_id: string } };

export type RewardedOutcome =
  /** The server verified the ad. `claim.reward_coins` / `claim.outcome` say what it granted. */
  | { status: 'verified'; claim: RewardedClaim; message: string }
  /** The ad finished but the network's confirmation hasn't reached the server yet. */
  | { status: 'pending'; claim: RewardedClaim | null; message: string }
  | { status: 'not_completed'; message: string }
  | { status: 'unavailable'; message: string }
  | { status: 'error'; message: string };

export type AdConsent = 'personalised' | 'non_personalised';

type AdsContextValue = {
  config: AdsClientConfig | null;
  /** LevelPlay is initialised, so banner/native/full-screen ads can load. */
  sdkReady: boolean;
  /** The consent question has been answered (or isn't needed on this build). Popups wait for it. */
  consentSettled: boolean;
  consent: AdConsent | null;
  setConsent: (consent: AdConsent) => void;
  /** True while a rewarded ad (or its verification) is in progress anywhere in the app. */
  watching: boolean;
  placement: (key: AdPlacementKey) => AdsClientConfig['placements'][number] | null;
  watchRewarded: (request: RewardedRequest) => Promise<RewardedOutcome>;
  /** Shows an optional interstitial if Admin enabled it and its caps allow; never throws. */
  showInterstitial: (key: AdPlacementKey) => Promise<boolean>;
  track: (event: AdEvent) => void;
  refreshConfig: () => Promise<void>;
};

const AdsContext = createContext<AdsContextValue | undefined>(undefined);

const CONSENT_KEY = 'minimax.ads.consent';
const CAPS_KEY = 'minimax.ads.caps';
const CONFIG_FRESH_MS = 5 * 60_000;
const EVENT_FLUSH_MS = 20_000;
const POLL_INTERVAL_MS = 1_500;
const POLL_ATTEMPTS = 12;
/** No optional full-screen ad within this long of another full-screen ad (rewarded included). */
const FULL_SCREEN_GAP_MS = 90_000;

const OUTCOME_MESSAGES: Record<string, string> = {
  tournament_closed: 'The ad finished, but joining for this tournament had already closed, so no ticket was added.',
  tickets_already_complete: 'You already have every ticket this tournament needs.',
  already_claimed_today: "Today's reward was already claimed.",
  campaign_inactive: 'This reward ended before the ad finished.',
  budget_spent: 'This reward ran out before the ad finished.',
  daily_limit: "You've reached today's limit for this reward.",
  already_redeemed: "You've already redeemed this code. Your coins are in your wallet.",
  limit_reached: 'This code reached its redemption limit before the ad finished.',
};

/** Plain-language text for why a verified claim granted nothing (the server sends a slug). */
export function claimOutcomeMessage(outcome: string | null): string | null {
  if (!outcome) return null;
  return OUTCOME_MESSAGES[outcome] ?? 'The ad was verified, but this reward is no longer available.';
}

function istDay(): string {
  // The server's daily rules use Asia/Kolkata; these local caps follow the same day boundary.
  return new Date(Date.now() + 5.5 * 3_600_000).toISOString().slice(0, 10);
}

async function readStore(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key);
  } catch {
    return null;
  }
}

function writeStore(key: string, value: string) {
  SecureStore.setItemAsync(key, value).catch(() => undefined);
}

function wait(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Everything ad-related, in one place, for the whole app:
 *  - the server's ad configuration (what Admin switched on, the public ad unit IDs);
 *  - the player's personalised-ads choice, asked once before the ad SDK starts;
 *  - LevelPlay initialisation, and a batched analytics stream to the server;
 *  - the one rewarded flow every feature uses: open a claim on the server, show the ad with
 *    the claim as LevelPlay's dynamic user ID, then wait for the server to confirm it from
 *    the network's signed callback. The app never decides a reward;
 *  - optional interstitials with their cooldowns and daily caps.
 *
 * Where the ad SDK can't run (web preview, Expo Go) and the server explicitly allows it
 * outside production, a clearly labelled test-ad dialog stands in for the ad so the flows
 * can be checked end to end. It never appears in a production build.
 */
export function AdsProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const { refresh: refreshWallet } = useWallet();
  const styles = useStyles();
  const [config, setConfig] = useState<AdsClientConfig | null>(null);
  const [consent, setConsentState] = useState<AdConsent | null>(null);
  const [consentLoaded, setConsentLoaded] = useState(false);
  const [sdkReady, setSdkReady] = useState(false);
  const [watching, setWatching] = useState(false);
  const [testAd, setTestAd] = useState<{ purpose: string } | null>(null);
  const testAdResolver = useRef<((result: RewardedShowResult) => void) | null>(null);
  const configFetchedAt = useRef(0);
  const queue = useRef<AdEvent[]>([]);
  const busy = useRef(false);
  const lastFullScreenAt = useRef(0);
  const lastShownAt = useRef<Record<string, number>>({});
  const caps = useRef<{ day: string; counts: Record<string, number> }>({ day: istDay(), counts: {} });
  const userId = user?.id ?? null;
  const sdkPresent = adSdkAvailable();

  const flush = useCallback(async () => {
    if (queue.current.length === 0 || userId === null) return;
    const batch = queue.current.splice(0, 50);
    try {
      await sendAdEvents(batch);
    } catch {
      // Analytics only: a lost batch is dropped rather than retried forever.
    }
  }, [userId]);

  const track = useCallback(
    (event: AdEvent) => {
      queue.current.push({ ...event, is_test: config?.test_mode ?? true });
      if (queue.current.length >= 20) void flush();
    },
    [config?.test_mode, flush]
  );

  const refreshConfig = useCallback(async () => {
    if (userId === null) return;
    try {
      const response = await fetchAdsConfig();
      setConfig(response.data);
      configFetchedAt.current = Date.now();
    } catch {
      // Keep the last configuration; with none, no ads run.
    }
  }, [userId]);

  useEffect(() => {
    if (userId === null) {
      setConfig(null);
      return;
    }
    void refreshConfig();
  }, [userId, refreshConfig]);

  useEffect(() => {
    (async () => {
      const [savedConsent, savedCaps] = await Promise.all([readStore(CONSENT_KEY), readStore(CAPS_KEY)]);
      if (savedConsent === 'personalised' || savedConsent === 'non_personalised') setConsentState(savedConsent);
      try {
        const parsed = savedCaps ? JSON.parse(savedCaps) : null;
        if (parsed && parsed.day === istDay() && typeof parsed.counts === 'object') caps.current = parsed;
      } catch {
        // Start the day's counts afresh.
      }
      setConsentLoaded(true);
    })();
  }, []);

  useEffect(() => {
    const timer = setInterval(() => void flush(), EVENT_FLUSH_MS);
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        if (Date.now() - configFetchedAt.current > CONFIG_FRESH_MS) void refreshConfig();
      } else {
        void flush();
      }
    });
    return () => {
      clearInterval(timer);
      subscription.remove();
    };
  }, [flush, refreshConfig]);

  const needsConsent = sdkPresent && config?.enabled === true && consentLoaded && consent === null;

  useEffect(() => {
    if (!sdkPresent || !config?.enabled || !config.levelplay_app_key || consent === null) {
      return;
    }
    let cancelled = false;
    initAdSdk({ appKey: config.levelplay_app_key, consent: consent === 'personalised', testMode: config.test_mode, onEvent: track }).then((ok) => {
      if (!cancelled) setSdkReady(ok);
    });
    return () => {
      cancelled = true;
    };
  }, [sdkPresent, config?.enabled, config?.levelplay_app_key, config?.test_mode, consent, track]);

  const setConsent = useCallback((value: AdConsent) => {
    setConsentState(value);
    writeStore(CONSENT_KEY, value);
  }, []);

  const placement = useCallback((key: AdPlacementKey) => config?.placements.find((item) => item.key === key) ?? null, [config]);

  const askTestAd = useCallback(
    (purpose: string) =>
      new Promise<RewardedShowResult>((resolve) => {
        testAdResolver.current = resolve;
        setTestAd({ purpose });
      }),
    []
  );

  const answerTestAd = (result: RewardedShowResult) => {
    testAdResolver.current?.(result);
    testAdResolver.current = null;
    setTestAd(null);
  };

  const pollClaim = useCallback(async (token: string): Promise<RewardedClaim | null> => {
    let latest: RewardedClaim | null = null;
    for (let attempt = 0; attempt < POLL_ATTEMPTS; attempt += 1) {
      try {
        latest = (await fetchRewardedClaim(token)).data;
        if (latest.status !== 'pending') return latest;
      } catch {
        // Keep trying: a dropped request doesn't mean the reward failed.
      }
      await wait(POLL_INTERVAL_MS);
    }
    return latest;
  }, []);

  const watchRewarded = useCallback(
    async (request: RewardedRequest): Promise<RewardedOutcome> => {
      if (busy.current) {
        return { status: 'error', message: 'An ad is already in progress.' };
      }
      busy.current = true;
      setWatching(true);

      try {
        let token: string;
        let dynamicUserId: string;
        let adUnitId: string | null;
        let placementKey: string;

        if (request.purpose === 'redeem_code') {
          token = request.claim.token;
          dynamicUserId = request.claim.dynamic_user_id;
          placementKey = 'redeem_verification';
          adUnitId = placement('redeem_verification')?.ad_unit_id ?? null;
        } else {
          try {
            const body =
              request.purpose === 'tournament_ticket'
                ? { purpose: request.purpose, tournament_id: request.tournamentId }
                : request.purpose === 'campaign'
                  ? { purpose: request.purpose, campaign_id: request.campaignId }
                  : { purpose: request.purpose };
            const claim = (await openRewardedClaim(body)).data;
            token = claim.token;
            dynamicUserId = claim.dynamic_user_id;
            adUnitId = claim.ad_unit_id;
            placementKey = claim.placement;
          } catch (error) {
            return { status: 'error', message: error instanceof ApiError ? error.message : "Couldn't reach Mini Max. Check your connection and try again." };
          }
        }

        let result: RewardedShowResult;
        if (sdkReady && adUnitId) {
          result = await showRewardedAd({ adUnitId, placement: placementKey, dynamicUserId, onEvent: track });
        } else if (config?.simulation_allowed) {
          result = await askTestAd(request.purpose);
          if (result === 'rewarded') {
            try {
              await simulateRewardedClaim(token);
            } catch {
              result = 'failed';
            }
          }
        } else {
          result = 'unavailable';
        }
        lastFullScreenAt.current = Date.now();

        if (result === 'rewarded') {
          const claim = await pollClaim(token);
          if (claim?.status === 'verified') {
            if (claim.reward_coins !== null) void refreshWallet({ force: true });
            return { status: 'verified', claim, message: claimOutcomeMessage(claim.outcome) ?? 'Reward confirmed.' };
          }
          if (claim === null || claim.status === 'pending') {
            return { status: 'pending', claim, message: "Your ad finished. We're waiting for the ad network to confirm it — this can take a minute. Check back shortly." };
          }
          return { status: 'error', message: "This ad couldn't be verified, so nothing was added." };
        }

        if (result === 'closed') {
          // The claim stays open: watching a full ad next time completes the same claim.
          return { status: 'not_completed', message: 'The ad was closed before the end, so nothing was added. Watch the full ad to earn it.' };
        }

        // Nothing was shown, so no callback can come: close the claim.
        void cancelRewardedClaim(token).catch(() => undefined);
        return {
          status: 'unavailable',
          message: sdkPresent ? 'No ad is available right now. Please try again in a few minutes.' : 'Rewarded ads play only in the Mini Max Android app.',
        };
      } finally {
        busy.current = false;
        setWatching(false);
      }
    },
    [askTestAd, config?.simulation_allowed, placement, pollClaim, refreshWallet, sdkPresent, sdkReady, track]
  );

  const showInterstitial = useCallback(
    async (key: AdPlacementKey) => {
      const item = placement(key);
      if (!item || item.format !== 'interstitial' || !sdkReady || busy.current) return false;
      const now = Date.now();
      if (now - lastFullScreenAt.current < FULL_SCREEN_GAP_MS) return false;
      if (item.cooldown_seconds && now - (lastShownAt.current[key] ?? 0) < item.cooldown_seconds * 1000) return false;
      if (caps.current.day !== istDay()) caps.current = { day: istDay(), counts: {} };
      if (item.daily_cap !== null && (caps.current.counts[key] ?? 0) >= item.daily_cap) return false;

      busy.current = true;
      try {
        const result = await showInterstitialAd({ adUnitId: item.ad_unit_id, placement: key, onEvent: track });
        if (result === 'shown') {
          lastShownAt.current[key] = Date.now();
          lastFullScreenAt.current = Date.now();
          caps.current.counts[key] = (caps.current.counts[key] ?? 0) + 1;
          writeStore(CAPS_KEY, JSON.stringify(caps.current));
        }
        return result === 'shown';
      } finally {
        busy.current = false;
      }
    },
    [placement, sdkReady, track]
  );

  const value = useMemo<AdsContextValue>(
    () => ({
      config,
      sdkReady,
      consentSettled: !needsConsent && consentLoaded,
      consent,
      setConsent,
      watching,
      placement,
      watchRewarded,
      showInterstitial,
      track,
      refreshConfig,
    }),
    [config, sdkReady, needsConsent, consentLoaded, consent, setConsent, watching, placement, watchRewarded, showInterstitial, track, refreshConfig]
  );

  return (
    <AdsContext.Provider value={value}>
      {children}
      <Sheet
        visible={needsConsent && userId !== null}
        onClose={() => undefined}
        dismissible={false}
        hideClose
        title="Ads in Mini Max"
        subtitle="Mini Max shows ads, including rewarded ads you choose to watch for tickets and rewards."
      >
        <Text style={styles.body}>
          Personalised ads use information such as your device's advertising ID so our ad partners (Unity LevelPlay, Google AdMob, Unity Ads) can show ads that match your interests. Non-personalised ads are
          still shown, but aren't based on your activity. You can change this any time in Settings.
        </Text>
        <View style={styles.actions}>
          <Button label="Allow personalised ads" onPress={() => setConsent('personalised')} />
          <Button label="Use non-personalised ads" variant="secondary" onPress={() => setConsent('non_personalised')} />
        </View>
      </Sheet>
      <Sheet visible={testAd !== null} onClose={() => answerTestAd('closed')} title="Test ad — QA only" subtitle="No real ad network is connected in this build.">
        <Text style={styles.body}>
          This stands in for a rewarded ad while testing. "Finish test ad" asks the test server to confirm it the way the ad network's callback would; closing it behaves like closing an ad early. It never
          appears in a production build and earns no real ad revenue.
        </Text>
        <View style={styles.actions}>
          <Button label="Finish test ad" onPress={() => answerTestAd('rewarded')} />
          <Button label="Close early" variant="secondary" onPress={() => answerTestAd('closed')} />
        </View>
      </Sheet>
    </AdsContext.Provider>
  );
}

export function useAds(): AdsContextValue {
  const context = useContext(AdsContext);

  if (!context) {
    throw new Error('useAds must be used within an AdsProvider');
  }

  return context;
}

const useStyles = makeStyles(({ colors: c }) => ({
  body: { ...TYPE.body, color: c.fg2 },
  actions: { gap: SPACE.xs, marginTop: SPACE.xs },
}));
