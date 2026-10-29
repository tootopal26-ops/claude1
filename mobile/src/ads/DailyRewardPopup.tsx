import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Animated, Pressable, Text, View } from 'react-native';

import { ApiError } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { Button } from '../components/Button';
import { CoinAmount } from '../components/CoinAmount';
import { CoinAsset } from '../components/CoinAsset';
import { Notice } from '../components/Notice';
import { Sheet } from '../components/sheet/Sheet';
import { DURATION, EASE, useReducedMotion } from '../theme/motion';
import { Icon } from '../theme/icons';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../theme/tokens';
import { formatCoins } from '../utils/coins';
import { useWallet } from '../wallet/WalletContext';
import { useAds } from './AdsContext';
import { useSound } from '../sound/SoundContext';
import { claimDailyRewardWithoutAd, dismissDailyReward, fetchDailyReward } from './api';
import type { DailyRewardStatus } from './types';

// One offer per app session and reward day: closing with X hides it until the app is opened
// again (the server's "don't show again today" is the only thing that hides it for the day).
let offeredFor: string | null = null;

type Phase = { kind: 'offer'; message: { tone: 'danger' | 'warning' | 'info'; text: string } | null } | { kind: 'working' } | { kind: 'done'; amount: string };

/**
 * The daily login reward, offered on Home once per app session when the server says the
 * player can claim today (feature on, deposits at the minimum, not claimed, not hidden for
 * today, and the ad placement live). The amount is drawn and kept by the server; it is
 * credited only after the ad network's verified callback, then the balance is refreshed.
 * X closes without claiming; X with "Don't show again today" ticked also tells the server
 * to hide the popup until the next reset (Asia/Kolkata).
 */
export function DailyRewardPopup() {
  const styles = useStyles();
  const { colors } = useTheme();
  const reduced = useReducedMotion();
  const { user } = useAuth();
  const { consentSettled, watchRewarded, watching } = useAds();
  const sound = useSound();
  const { wallet, refresh: refreshWallet } = useWallet();
  const [status, setStatus] = useState<DailyRewardStatus | null>(null);
  const [visible, setVisible] = useState(false);
  const [hideToday, setHideToday] = useState(false);
  const [phase, setPhase] = useState<Phase>({ kind: 'offer', message: null });
  const [closing, setClosing] = useState(false);
  const coinScale = useRef(new Animated.Value(reduced ? 1 : 0.6)).current;
  const checking = useRef(false);

  const check = useCallback(async () => {
    if (!user || !consentSettled || visible || checking.current) return;
    checking.current = true;
    try {
      const response = await fetchDailyReward();
      if (response.data.show_popup && offeredFor !== response.data.day) {
        offeredFor = response.data.day;
        setStatus(response.data);
        setHideToday(false);
        setPhase({ kind: 'offer', message: null });
        setVisible(true);
      }
    } catch {
      // No popup when the status can't be loaded; nothing is claimed or assumed.
    } finally {
      checking.current = false;
    }
  }, [user, consentSettled, visible]);

  useFocusEffect(
    useCallback(() => {
      void check();
    }, [check])
  );

  useEffect(() => {
    if (!visible) return;
    coinScale.setValue(reduced ? 1 : 0.6);
    if (!reduced) {
      // One restrained pop when the popup opens — no looping animation.
      Animated.spring(coinScale, { toValue: 1, damping: 12, stiffness: 180, mass: 1, useNativeDriver: true }).start();
    }
  }, [visible, phase.kind, reduced, coinScale]);

  const close = async () => {
    if (phase.kind === 'working' || closing) return;
    if (hideToday && phase.kind !== 'done') {
      setClosing(true);
      try {
        await dismissDailyReward();
      } catch {
        // Not saved: it may be offered again next time the app opens. Nothing was claimed.
      } finally {
        setClosing(false);
      }
    }
    setVisible(false);
  };

  const claim = async () => {
    if (!status || phase.kind === 'working' || watching) return;
    setPhase({ kind: 'working' });

    if (!status.requires_ad) {
      try {
        const response = await claimDailyRewardWithoutAd();
        void refreshWallet({ force: true });
        sound.playReward();
        sound.playCoin();
        setPhase({ kind: 'done', amount: response.data.amount });
      } catch (error) {
        sound.playError();
        setPhase({ kind: 'offer', message: { tone: 'danger', text: error instanceof ApiError ? error.message : "Couldn't reach Mini Max. Check your connection and try again." } });
      }
      return;
    }

    const outcome = await watchRewarded({ purpose: 'daily_reward' });
    if (outcome.status === 'verified' && outcome.claim.reward_coins !== null) {
      sound.playReward();
      sound.playCoin();
      setPhase({ kind: 'done', amount: outcome.claim.reward_coins });
      return;
    }
    if (outcome.status === 'verified') {
      sound.playWarning();
      setPhase({ kind: 'offer', message: { tone: 'info', text: outcome.message } });
      return;
    }
    if (outcome.status !== 'pending') sound.playWarning();
    setPhase({
      kind: 'offer',
      message: { tone: outcome.status === 'pending' ? 'info' : outcome.status === 'not_completed' ? 'warning' : 'danger', text: outcome.message },
    });
  };

  if (!status) return null;

  const busy = phase.kind === 'working';

  return (
    <Sheet visible={visible} onClose={close} dismissible={!busy && !closing} title={phase.kind === 'done' ? 'Reward claimed' : status.title}>
      <View style={styles.hero}>
        <Animated.View style={{ transform: [{ scale: coinScale }] }}>
          <CoinAsset size={72} />
        </Animated.View>
        {phase.kind === 'done' ? (
          <>
            <Text style={styles.amount}>+{formatCoins(phase.amount)}</Text>
            <Text style={styles.body}>Added to your Deposit balance.</Text>
            {wallet ? <CoinAmount value={wallet.total_balance} size={16} suffix="total" textStyle={styles.balance} /> : null}
          </>
        ) : (
          <>
            <View style={styles.rangeRow}>
              <CoinAmount value={status.range.min} size={18} textStyle={styles.range} />
              {status.range.min !== status.range.max ? (
                <>
                  <Text style={styles.rangeDash}>–</Text>
                  <CoinAmount value={status.range.max} size={18} textStyle={styles.range} />
                </>
              ) : null}
            </View>
            <Text style={styles.body}>{status.description}</Text>
          </>
        )}
      </View>

      {phase.kind === 'offer' && phase.message ? <Notice tone={phase.message.tone}>{phase.message.text}</Notice> : null}

      {phase.kind === 'done' ? (
        <Button label="Done" onPress={() => setVisible(false)} />
      ) : (
        <>
          <Button label={status.requires_ad ? 'Watch Ad & Claim' : 'Claim'} icon={status.requires_ad ? 'play' : undefined} onPress={claim} loading={busy} disabled={watching && !busy} />
          <Pressable style={styles.checkboxRow} onPress={() => setHideToday((value) => !value)} disabled={busy} accessibilityRole="checkbox" aria-checked={hideToday} hitSlop={6}>
            <View style={[styles.checkbox, hideToday && styles.checkboxChecked]}>{hideToday && <Icon name="check" size={14} color={colors.onBrand} />}</View>
            <Text style={styles.checkboxLabel}>Don&apos;t show again today</Text>
          </Pressable>
        </>
      )}
    </Sheet>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  hero: { alignItems: 'center', gap: SPACE.xs, paddingVertical: SPACE.xs },
  amount: { fontSize: 32, lineHeight: 38, fontWeight: '800', color: s.success },
  balance: { ...TYPE.bodyStrong, color: c.fg },
  rangeRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  range: { ...TYPE.section, color: c.fg },
  rangeDash: { ...TYPE.section, color: c.fg3 },
  body: { ...TYPE.body, color: c.fg2, textAlign: 'center' },
  checkboxRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, minHeight: 44, alignSelf: 'center' },
  checkbox: { width: 22, height: 22, borderRadius: 6, borderWidth: 1.5, borderColor: c.borderStrong, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' },
  checkboxChecked: { backgroundColor: c.brand, borderColor: c.brand },
  checkboxLabel: { ...TYPE.body, color: c.fg2 },
}));
