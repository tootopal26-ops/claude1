import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAds } from '../../src/ads/AdsContext';
import { AdSlot } from '../../src/ads/AdSlot';
import { AmbientGlow } from '../../src/components/AmbientGlow';
import { Button } from '../../src/components/Button';
import { ConfettiBurst } from '../../src/components/ConfettiBurst';
import { useBottomNavSpace } from '../../src/components/navigation/BottomNav';
import { Notice } from '../../src/components/Notice';
import { Skeleton } from '../../src/components/states/States';
import { TokenAsset } from '../../src/components/TokenAsset';
import { Sheet } from '../../src/components/sheet/Sheet';
import { useSound } from '../../src/sound/SoundContext';
import { makeStyles } from '../../src/theme/ThemeContext';
import { ICON_COLORS, MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../../src/theme/tokens';
import { fetchSpinStatus } from '../../src/tokens/api';
import { SpinButton } from '../../src/tokens/SpinButton';
import { useRefreshTokensOnFocus, useTokens } from '../../src/tokens/TokenContext';
import { TokenBalancePill } from '../../src/tokens/TokenBalancePill';
import type { SpinStatus } from '../../src/tokens/types';
import { FRAME_ASPECT, SpinWheel } from '../../src/tokens/SpinWheel';
import { pushOnce } from '../../src/utils/navigation';

type Message = { tone: 'success' | 'danger' | 'info' | 'warning'; text: string } | null;

/**
 * The Spin tab: a premium rewarded-ad spin wheel that pays out 1-5 tokens. Every number on
 * the wheel, every probability behind it and every token ever credited comes from the
 * server (SpinService) — this screen only ever shows and animates to a result the server
 * already confirmed from a verified rewarded ad; it never decides or guesses one itself.
 */
export default function SpinScreen() {
  const styles = useStyles();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const bottomSpace = useBottomNavSpace();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const wheelSize = Math.min(contentWidth, 320);
  const { watchRewarded } = useAds();
  const { balance: tokenBalance } = useTokens();
  const sound = useSound();
  useRefreshTokensOnFocus();

  const [status, setStatus] = useState<SpinStatus | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [adBusy, setAdBusy] = useState(false);
  const [wheelTurning, setWheelTurning] = useState(false);
  const [pendingNumber, setPendingNumber] = useState<number | null>(null);
  const [requestId, setRequestId] = useState(0);
  const [message, setMessage] = useState<Message>(null);
  const [winPopup, setWinPopup] = useState<{ tokens: number } | null>(null);

  const loadStatus = useCallback(async () => {
    try {
      const response = await fetchSpinStatus();
      setStatus(response.data);
      setLoadFailed(false);
    } catch {
      setLoadFailed(true);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void loadStatus();
    }, [loadStatus]),
  );

  const canSpin = Boolean(status?.available) && !adBusy && !wheelTurning;

  const onSpinPress = async () => {
    if (!canSpin) return;
    setMessage(null);
    setAdBusy(true);
    const outcome = await watchRewarded({ purpose: 'spin' });
    setAdBusy(false);

    if (outcome.status === 'verified' && outcome.claim.reward_tokens !== null) {
      setPendingNumber(outcome.claim.reward_tokens);
      setRequestId((id) => id + 1);
      setWheelTurning(true);
    } else {
      if (outcome.status !== 'pending') sound.playWarning();
      setMessage({ tone: outcome.status === 'pending' ? 'info' : 'warning', text: outcome.message });
    }
    await loadStatus();
  };

  const onSpinComplete = useCallback(
    (landedNumber: number) => {
      setWheelTurning(false);
      // The reward was already verified and credited before the wheel ever started turning —
      // this is only the moment the animation catches up, which is exactly when to celebrate it.
      sound.playReward();
      sound.playCoin();
      setWinPopup({ tokens: landedNumber });
    },
    [sound],
  );

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xs, paddingBottom: bottomSpace }]} showsVerticalScrollIndicator={false}>
        <View style={[styles.column, { width: contentWidth }]}>
          <View style={styles.headerRow}>
            <Text style={styles.screenTitle} accessibilityRole="header">
              Spin &amp; Win
            </Text>
            <TokenBalancePill balance={tokenBalance} onExchangePress={() => pushOnce('/token-exchange')} />
          </View>

          {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
          {loadFailed && !status ? <Notice tone="danger">Couldn't load the spin wheel. Pull down to try again.</Notice> : null}

          <View style={styles.wheelArea}>
            {status ? (
              <SpinWheel segments={status.segments} targetNumber={pendingNumber} requestId={requestId} onSpinComplete={onSpinComplete} size={wheelSize} />
            ) : (
              <Skeleton width={wheelSize} height={wheelSize / FRAME_ASPECT} radius={RADIUS.xl} />
            )}
          </View>

          <SpinButton onPress={onSpinPress} loading={adBusy} disabled={!canSpin} />

          {status && !status.enabled ? <Notice tone="info">Spin isn't running right now. Check back later.</Notice> : null}
          {status && status.enabled && !status.ads_available ? <Notice tone="info">No ad is available for a spin right now. Try again shortly.</Notice> : null}

          <AdSlot placement="spin_feed" style={styles.adSlot} />
        </View>
      </ScrollView>

      <Sheet visible={winPopup !== null} onClose={() => setWinPopup(null)} title="Spin result" hideClose variant="center">
        <View style={styles.winBody}>
          {winPopup ? <ConfettiBurst /> : null}
          <Text style={styles.winTitle}>YOU WON!</Text>
          <View style={styles.winAmountRow}>
            <TokenAsset size={40} />
            <Text style={styles.winNumber}>{winPopup?.tokens ?? 0}</Text>
          </View>
          <Text style={styles.winLabel}>TOKENS</Text>
          <Button label="Continue" onPress={() => setWinPopup(null)} style={styles.winContinue} />
        </View>
      </Sheet>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center' },
  column: { gap: SPACE.md },
  headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.sm, minHeight: 44 },
  screenTitle: { ...TYPE.screenTitle, color: c.fg },
  wheelArea: { alignItems: 'center', paddingVertical: SPACE.sm },
  adSlot: { marginTop: SPACE.xs },
  winBody: { alignItems: 'center', gap: SPACE.xxs, paddingTop: SPACE.sm },
  winTitle: { ...TYPE.hero, fontSize: 26, letterSpacing: 1, color: ICON_COLORS.gold.main, textAlign: 'center' },
  winAmountRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, marginTop: SPACE.sm },
  winNumber: { fontSize: 48, lineHeight: 54, fontWeight: '800', color: c.fg },
  winLabel: { ...TYPE.label, fontSize: 13, letterSpacing: 2, color: ICON_COLORS.gold.main, marginBottom: SPACE.sm },
  winContinue: { width: '100%', marginTop: SPACE.sm },
}));
