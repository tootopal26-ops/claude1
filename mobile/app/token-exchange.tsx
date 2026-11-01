import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { RefreshControl, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAds } from '../src/ads/AdsContext';
import { ApiError } from '../src/api/client';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { Button } from '../src/components/Button';
import { CoinAmount } from '../src/components/CoinAmount';
import { ConfettiBurst } from '../src/components/ConfettiBurst';
import { Notice } from '../src/components/Notice';
import { Sheet } from '../src/components/sheet/Sheet';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { StatusChip } from '../src/components/StatusChip';
import { TokenAmount } from '../src/components/TokenAmount';
import { useSound } from '../src/sound/SoundContext';
import { glassPanel } from '../src/theme/glass';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../src/theme/tokens';
import { fetchExchangeHistory, fetchExchangeTiers } from '../src/tokens/api';
import { useTokens } from '../src/tokens/TokenContext';
import type { ExchangeHistoryItem, ExchangeTier } from '../src/tokens/types';
import { formatDateTime } from '../src/utils/time';

type Message = { tone: 'success' | 'danger' | 'info' | 'warning'; text: string } | null;

function errorText(error: unknown, fallback: string) {
  if (error instanceof ApiError) {
    const first = error.errors ? Object.values(error.errors)[0]?.[0] : undefined;
    return first ?? error.message ?? fallback;
  }
  return fallback;
}

/**
 * The 10 fixed token-for-coins tiers (TokenExchangeService::TIERS) — the token amounts and
 * coin payouts here are always the server's own figures, never recomputed locally, so the
 * app and the ledger can never drift. An exchange needs a verified rewarded ad, exactly like
 * a spin: tokens are debited and coins credited to the existing Deposit wallet only once
 * RewardedClaimService confirms it — never on tapping Confirm, which only opens the claim.
 */
export default function TokenExchangeScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const { watchRewarded, watching } = useAds();
  const { balance } = useTokens();
  const sound = useSound();

  const [tiers, setTiers] = useState<ExchangeTier[] | null>(null);
  const [history, setHistory] = useState<ExchangeHistoryItem[] | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [message, setMessage] = useState<Message>(null);
  const [confirmTier, setConfirmTier] = useState<ExchangeTier | null>(null);
  const [exchanging, setExchanging] = useState(false);
  const [successCoins, setSuccessCoins] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [tiersResponse, historyResponse] = await Promise.all([fetchExchangeTiers(), fetchExchangeHistory().catch(() => ({ data: [] as ExchangeHistoryItem[] }))]);
      setTiers(tiersResponse.data);
      setHistory(historyResponse.data);
      setLoadFailed(false);
    } catch {
      setLoadFailed(true);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  const onRefresh = async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  };

  const onConfirm = async () => {
    if (!confirmTier) return;
    const tier = confirmTier;
    setExchanging(true);
    setMessage(null);
    const outcome = await watchRewarded({ purpose: 'token_exchange', exchangeTier: tier.index });
    setExchanging(false);
    setConfirmTier(null);

    if (outcome.status === 'verified' && outcome.claim.reward_coins !== null) {
      sound.playReward();
      sound.playCoin();
      setSuccessCoins(outcome.claim.reward_coins);
    } else {
      if (outcome.status !== 'pending') sound.playWarning();
      setMessage({ tone: outcome.status === 'pending' ? 'info' : 'warning', text: outcome.message });
    }
    await load();
  };

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView
        contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xs, paddingBottom: insets.bottom + SPACE.xxl }]}
        refreshControl={<RefreshControl tintColor={colors.brandText} colors={[colors.brand]} progressBackgroundColor={colors.surface3} refreshing={refreshing} onRefresh={onRefresh} />}
      >
        <View style={[styles.column, { width: contentWidth }]}>
          <View style={styles.balanceCard}>
            <Text style={styles.balanceLabel}>Your token balance</Text>
            <TokenAmount value={balance} size={26} textStyle={styles.balanceValue} />
            <Text style={styles.meta}>Exchange your tokens for coins in your Deposit wallet. Every exchange needs one rewarded ad, just like a spin.</Text>
          </View>

          {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}

          {!tiers && loadFailed ? (
            <ErrorState compact title="Couldn't load exchange tiers" onRetry={() => load()} />
          ) : !tiers ? (
            <View style={styles.skeletons}>
              {[0, 1, 2, 3].map((index) => (
                <Skeleton key={index} height={76} radius={RADIUS.lg} />
              ))}
            </View>
          ) : (
            <View style={styles.tierList}>
              {tiers.map((tier) => {
                const bonus = Number(tier.bonus_percent);
                const affordable = balance !== null && balance >= tier.tokens;

                return (
                  <View key={tier.index} style={styles.tierRow}>
                    <View style={styles.tierText}>
                      <View style={styles.tierAmounts}>
                        <TokenAmount value={tier.tokens} size={18} />
                        <Icon name="chevron-right" size={14} color={colors.fg3} />
                        <CoinAmount value={tier.coins} size={18} />
                      </View>
                      {bonus > 0 ? <StatusChip label={`+${bonus}% bonus`} tone="success" /> : <Text style={styles.standardLabel}>Standard rate</Text>}
                    </View>
                    <Button
                      label={affordable ? 'Exchange' : 'Not enough'}
                      size="sm"
                      onPress={() => setConfirmTier(tier)}
                      disabled={!affordable || watching}
                      accessibilityLabel={`Exchange ${tier.tokens} tokens for ${tier.coins} coins`}
                    />
                  </View>
                );
              })}
            </View>
          )}

          <Text style={styles.sectionTitle} accessibilityRole="header">
            Exchange history
          </Text>
          {history === null ? (
            <Skeleton height={64} radius={RADIUS.md} />
          ) : history.length === 0 ? (
            <EmptyState compact icon="wallet" title="No exchanges yet" message="Every token exchange you complete appears here." />
          ) : (
            <View style={styles.skeletons}>
              {history.map((item) => (
                <View key={item.id} style={styles.historyRow}>
                  <View style={styles.historyText}>
                    <View style={styles.tierAmounts}>
                      <TokenAmount value={item.tokens_required} size={14} />
                      <Icon name="chevron-right" size={12} color={colors.fg3} />
                      <CoinAmount value={item.coins_received} size={14} />
                    </View>
                    <Text style={styles.meta}>{formatDateTime(item.created_at)}</Text>
                  </View>
                  <StatusChip label={item.status === 'credited' ? 'Credited' : 'Failed'} tone={item.status === 'credited' ? 'success' : 'danger'} />
                </View>
              ))}
            </View>
          )}
        </View>
      </ScrollView>

      <Sheet visible={confirmTier !== null} onClose={() => (exchanging ? undefined : setConfirmTier(null))} dismissible={!exchanging} title="Confirm exchange">
        <Text style={styles.body}>CONFIRM EXCHANGE</Text>
        <View style={styles.confirmRow}>
          <TokenAmount value={confirmTier?.tokens} size={22} textStyle={styles.confirmValue} suffix="Tokens" />
        </View>
        <View style={styles.confirmArrow}>
          <Icon name="chevron-left" size={20} color={colors.fg3} />
        </View>
        <View style={styles.confirmRow}>
          <CoinAmount value={confirmTier?.coins} size={22} textStyle={styles.confirmValue} suffix="Coins" />
        </View>
        <Text style={styles.meta}>Current balance: {balance ?? 0} tokens</Text>
        <View style={styles.actions}>
          <Button label="Cancel" variant="secondary" onPress={() => setConfirmTier(null)} disabled={exchanging} style={styles.actionButton} />
          <Button label="Confirm" onPress={onConfirm} loading={exchanging} style={styles.actionButton} />
        </View>
      </Sheet>

      <Sheet visible={successCoins !== null} onClose={() => setSuccessCoins(null)} title="Exchange successful!">
        <View style={styles.successBody}>
          {successCoins !== null ? <ConfettiBurst count={18} /> : null}
          <Text style={styles.successTitle}>EXCHANGE SUCCESSFUL!</Text>
          <CoinAmount value={successCoins} size={28} textStyle={styles.successAmount} suffix="Coins" />
          <Text style={styles.body}>successfully credited to your Deposit Wallet.</Text>
          <Button label="Continue" onPress={() => setSuccessCoins(null)} style={styles.successContinue} />
        </View>
      </Sheet>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center' },
  column: { gap: SPACE.md },
  balanceCard: { ...glassPanel(c, 'surface2'), borderRadius: RADIUS.xl, padding: SPACE.md, gap: SPACE.xxs, alignItems: 'center' },
  balanceLabel: { ...TYPE.meta, color: c.fg3 },
  balanceValue: { fontSize: 26, lineHeight: 32, fontWeight: '800', color: c.fg },
  body: { ...TYPE.body, color: c.fg2, textAlign: 'center' },
  meta: { ...TYPE.meta, color: c.fg3 },
  skeletons: { gap: SPACE.xs },
  tierList: { gap: SPACE.xs },
  tierRow: { ...glassPanel(c), flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, borderRadius: RADIUS.lg, padding: SPACE.sm },
  tierText: { flex: 1, minWidth: 0, gap: 4 },
  tierAmounts: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  standardLabel: { ...TYPE.meta, color: c.fg3 },
  sectionTitle: { ...TYPE.section, color: c.fg, marginTop: SPACE.xs },
  historyRow: { ...glassPanel(c), flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.sm, borderRadius: RADIUS.md, padding: SPACE.sm },
  historyText: { flex: 1, minWidth: 0, gap: 2 },
  confirmRow: { alignItems: 'center', marginTop: SPACE.xs },
  confirmValue: { fontSize: 20, color: c.fg },
  confirmArrow: { alignItems: 'center', transform: [{ rotate: '-90deg' }] },
  actions: { flexDirection: 'row', gap: SPACE.sm, marginTop: SPACE.md },
  actionButton: { flex: 1 },
  successBody: { alignItems: 'center', gap: SPACE.xxs, position: 'relative' },
  successTitle: { ...TYPE.section, fontSize: 20, color: s.success, textAlign: 'center', marginTop: SPACE.xs },
  successAmount: { fontSize: 24, color: c.fg },
  successContinue: { width: '100%', marginTop: SPACE.sm },
}));
