import { Stack, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Switch, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAds } from '../src/ads/AdsContext';
import { fetchAdCampaigns, fetchAdsEarning, fetchAdsEarningHistory, setAdsEarningPreference } from '../src/ads/api';
import type { AdRewardCampaign, AdsEarningHistoryItem, AdsEarningStatus } from '../src/ads/types';
import { ApiError } from '../src/api/client';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { Button } from '../src/components/Button';
import { CoinAmount } from '../src/components/CoinAmount';
import { CoinAsset } from '../src/components/CoinAsset';
import { Notice } from '../src/components/Notice';
import { Sheet } from '../src/components/sheet/Sheet';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { StatusChip, type ChipTone } from '../src/components/StatusChip';
import { ProgressBar } from '../src/components/visual/ProgressBar';
import { glassPanel } from '../src/theme/glass';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE, TYPE } from '../src/theme/tokens';
import { formatCoins } from '../src/utils/coins';
import { formatDate, formatDateTime } from '../src/utils/time';

type Message = { tone: 'success' | 'danger' | 'info' | 'warning'; text: string } | null;

const HISTORY_STATUS: Record<AdsEarningHistoryItem['status'], { label: string; tone: ChipTone }> = {
  credited: { label: 'Credited', tone: 'success' },
  adjusted: { label: 'Adjusted', tone: 'info' },
  pending: { label: 'Pending', tone: 'warning' },
  processing: { label: 'Processing', tone: 'info' },
  below_minimum: { label: 'Below minimum', tone: 'neutral' },
};

function errorText(error: unknown, fallback: string) {
  if (error instanceof ApiError) {
    const first = error.errors ? Object.values(error.errors)[0]?.[0] : undefined;
    return first ?? error.message ?? fallback;
  }
  return fallback;
}

/**
 * Ads Earning: the weekly revenue share. Everything on it comes from the server — the
 * switch, the deposit progress, this week's verified ads, pending and credited coins, and
 * the settlement history. Coins are credited only by the weekly settlement, never per ad,
 * so no amount per ad is ever shown. Fixed-reward campaigns, when Admin runs one, are a
 * separate placement with their own stated reward.
 */
export default function AdsEarningScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const { watchRewarded, watching, consentSettled } = useAds();

  const [status, setStatus] = useState<AdsEarningStatus | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [toggling, setToggling] = useState(false);
  const [message, setMessage] = useState<Message>(null);
  const [history, setHistory] = useState<AdsEarningHistoryItem[] | null>(null);
  const [historyPage, setHistoryPage] = useState({ current: 0, last: 1 });
  const [historyLoading, setHistoryLoading] = useState(false);
  const [campaigns, setCampaigns] = useState<AdRewardCampaign[]>([]);
  const [claimingId, setClaimingId] = useState<number | null>(null);
  const [helpOpen, setHelpOpen] = useState(false);

  const loadStatus = useCallback(async () => {
    try {
      const [earning, offers] = await Promise.all([fetchAdsEarning(), fetchAdCampaigns().catch(() => ({ data: [] as AdRewardCampaign[] }))]);
      setStatus(earning.data);
      setCampaigns(offers.data);
      setLoadFailed(false);
    } catch {
      setLoadFailed(true);
    }
  }, []);

  const loadHistory = useCallback(async (page: number) => {
    setHistoryLoading(true);
    try {
      const response = await fetchAdsEarningHistory(page);
      setHistory((previous) => (page === 1 ? response.data : [...(previous ?? []), ...response.data]));
      setHistoryPage({ current: response.meta.current_page, last: response.meta.last_page });
    } catch {
      if (page === 1) setHistory((previous) => previous ?? []);
    } finally {
      setHistoryLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void loadStatus();
      void loadHistory(1);
    }, [loadStatus, loadHistory])
  );

  const onRefresh = async () => {
    setRefreshing(true);
    await Promise.all([loadStatus(), loadHistory(1)]);
    setRefreshing(false);
  };

  const onToggle = async (enabled: boolean) => {
    if (!status || toggling) return;
    setToggling(true);
    setMessage(null);
    try {
      const response = await setAdsEarningPreference(enabled);
      setStatus(response.data);
      setMessage({ tone: 'success', text: enabled ? 'Ads Earning is on. Verified ads you watch here now count toward this week.' : 'Ads Earning is off. Your earlier progress and rewards are kept.' });
    } catch (error) {
      setMessage({ tone: 'danger', text: errorText(error, "Couldn't change Ads Earning. Try again.") });
    } finally {
      setToggling(false);
    }
  };

  const onWatch = async () => {
    setMessage(null);
    const outcome = await watchRewarded({ purpose: 'ads_earning' });
    if (outcome.status === 'verified') {
      const counted = outcome.claim.counts_for_earning;
      setMessage(
        counted
          ? { tone: 'success', text: "Verified — this ad counts toward this week's Ads Earning." }
          : { tone: 'info', text: "The ad was verified but didn't count (today's limit or eligibility changed)." }
      );
    } else {
      setMessage({ tone: outcome.status === 'pending' ? 'info' : 'warning', text: outcome.message });
    }
    await loadStatus();
  };

  const onClaimCampaign = async (campaign: AdRewardCampaign) => {
    setMessage(null);
    setClaimingId(campaign.id);
    const outcome = await watchRewarded({ purpose: 'campaign', campaignId: campaign.id });
    setClaimingId(null);
    if (outcome.status === 'verified' && outcome.claim.reward_coins !== null) {
      setMessage({ tone: 'success', text: `${formatCoins(outcome.claim.reward_coins)} coins added to your Deposit balance.` });
    } else {
      setMessage({ tone: outcome.status === 'verified' || outcome.status === 'pending' ? 'info' : 'warning', text: outcome.message });
    }
    await loadStatus();
  };

  const eligibility = status?.eligibility;
  const eligibleProgress = eligibility && Number(eligibility.required) > 0 ? Number(eligibility.total) / Number(eligibility.required) : 1;
  const stateChip: { label: string; tone: ChipTone } | null = !status
    ? null
    : !status.feature_enabled
      ? { label: 'Not available', tone: 'neutral' }
      : status.paused
        ? { label: 'Paused', tone: 'warning' }
        : status.opted_in
          ? { label: 'On', tone: 'success' }
          : { label: 'Off', tone: 'neutral' };
  const canWatch = Boolean(status?.available && status.opted_in && eligibility?.eligible && status.units_today < status.daily_limit);

  return (
    <View style={styles.screen}>
      <Stack.Screen
        options={{
          headerRight: () => (
            <Pressable onPress={() => setHelpOpen(true)} accessibilityRole="button" accessibilityLabel="How Ads Earning works" hitSlop={8} style={styles.helpButton}>
              <Icon name="info" size={20} color={colors.fg2} />
            </Pressable>
          ),
        }}
      />
      <AmbientGlow />
      <ScrollView
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}
        refreshControl={<RefreshControl tintColor={colors.brandText} colors={[colors.brand]} progressBackgroundColor={colors.surface3} refreshing={refreshing} onRefresh={onRefresh} />}
      >
        <View style={[styles.column, { width: contentWidth }]}>
          {!status && loadFailed ? (
            <View style={styles.card}>
              <ErrorState compact title="Couldn't load Ads Earning" onRetry={() => loadStatus()} />
            </View>
          ) : !status || !eligibility ? (
            <>
              <Skeleton height={200} radius={RADIUS.xl} />
              <Skeleton height={140} radius={RADIUS.xl} />
            </>
          ) : (
            <>
              <View style={styles.card}>
                <View style={styles.statusHead}>
                  <CoinAsset size={44} />
                  <View style={styles.statusText}>
                    <Text style={styles.cardTitle}>Ads Earning</Text>
                    {stateChip ? <StatusChip label={stateChip.label} tone={stateChip.tone} /> : null}
                  </View>
                  {status.can_toggle && status.feature_enabled ? (
                    <View style={styles.switchWrap}>
                      {toggling ? <ActivityIndicator size="small" color={colors.fg3} /> : null}
                      <Switch
                        value={status.opted_in}
                        onValueChange={onToggle}
                        disabled={toggling || !consentSettled || (!status.opted_in && !eligibility.eligible)}
                        trackColor={{ false: colors.surface3, true: colors.brand }}
                        thumbColor={colors.fg}
                        accessibilityLabel="Ads Earning"
                      />
                    </View>
                  ) : null}
                </View>
                <Text style={styles.body}>{status.explanation}</Text>
                {eligibility.eligible ? (
                  <Text style={styles.eligibleLine}>
                    <Icon name="check-circle" size={14} /> You&apos;re eligible — your successful deposits meet the minimum.
                  </Text>
                ) : null}
                {!status.can_toggle && status.feature_enabled ? <Text style={styles.meta}>Mini Max manages this switch for your account right now.</Text> : null}
              </View>

              {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}

              {!eligibility.eligible ? (
                <View style={styles.card}>
                  <Text style={styles.cardTitle}>Unlock Ads Earning</Text>
                  <View style={styles.progressRow}>
                    <CoinAmount value={eligibility.total} size={18} textStyle={styles.progressValue} />
                    <Text style={styles.progressOf}>/</Text>
                    <CoinAmount value={eligibility.required} size={18} textStyle={styles.progressValue} />
                    <Text style={styles.progressPct}>{Math.min(100, Math.floor(eligibleProgress * 100))}%</Text>
                  </View>
                  <ProgressBar value={eligibleProgress} accessibilityLabel="Successful deposits toward the Ads Earning minimum" />
                  <Text style={styles.body}>
                    Complete {formatCoins(eligibility.remaining)} more in successful deposits to unlock Ads Earning and become eligible for qualifying ad rewards.
                  </Text>
                </View>
              ) : null}

              <View style={styles.card}>
                <View style={styles.rowBetween}>
                  <Text style={styles.cardTitle}>This week</Text>
                  <Text style={styles.meta}>
                    {formatDate(status.period.start)} – {formatDate(status.period.end)}
                  </Text>
                </View>
                <View style={styles.tiles}>
                  <View style={styles.tile}>
                    <Text style={styles.tileLabel}>Verified ads</Text>
                    <Text style={styles.tileValue}>{status.units_this_period}</Text>
                    <Text style={styles.tileHint}>
                      Today {status.units_today}/{status.daily_limit}
                    </Text>
                  </View>
                  <View style={styles.tile}>
                    <Text style={styles.tileLabel}>Pending</Text>
                    <CoinAmount value={status.pending_coins} size={16} textStyle={[styles.tileValue, styles.pending]} />
                    <Text style={styles.tileHint}>Being settled</Text>
                  </View>
                  <View style={styles.tile}>
                    <Text style={styles.tileLabel}>Credited</Text>
                    <CoinAmount value={status.credited_coins} size={16} textStyle={[styles.tileValue, styles.credited]} />
                    <Text style={styles.tileHint}>All time</Text>
                  </View>
                </View>
                <Text style={styles.meta}>
                  {status.last_settlement_at ? `Last settlement: ${formatDateTime(status.last_settlement_at)}` : 'No settlement has been completed yet.'} Coins are added only at the weekly settlement, from
                  verified ad revenue — there&apos;s no fixed amount per ad.
                </Text>
                {status.opted_in && eligibility.eligible ? (
                  <Button
                    label={status.units_today >= status.daily_limit ? "Today's limit reached" : 'Watch ad'}
                    icon="play"
                    onPress={onWatch}
                    loading={watching && claimingId === null}
                    disabled={!canWatch || watching}
                  />
                ) : null}
                {status.opted_in && !status.available && status.feature_enabled ? <Text style={styles.meta}>Earning ads aren&apos;t available right now. Check back later.</Text> : null}
              </View>

              {campaigns.length > 0 ? (
                <View style={styles.card}>
                  <Text style={styles.cardTitle}>Bonus rewards</Text>
                  <Text style={styles.meta}>A fixed reward for one complete ad, credited after the ad network confirms it. Separate from Ads Earning.</Text>
                  {campaigns.map((campaign) => (
                    <View key={campaign.id} style={styles.campaign}>
                      <View style={styles.campaignText}>
                        <Text style={styles.campaignName} numberOfLines={2}>
                          {campaign.name}
                        </Text>
                        <CoinAmount value={campaign.reward_coins} size={14} textStyle={styles.campaignReward} />
                      </View>
                      <Button
                        label={campaign.available ? 'Claim' : campaign.reason === 'daily_limit' ? 'Done today' : 'Unavailable'}
                        size="sm"
                        onPress={() => onClaimCampaign(campaign)}
                        loading={claimingId === campaign.id}
                        disabled={!campaign.available || watching}
                      />
                    </View>
                  ))}
                </View>
              ) : null}
            </>
          )}

          <Text style={styles.sectionTitle} accessibilityRole="header">
            Reward history
          </Text>
          {history === null ? (
            <View style={styles.skeletons}>
              {[0, 1].map((index) => (
                <Skeleton key={index} height={64} radius={RADIUS.md} />
              ))}
            </View>
          ) : history.length === 0 ? (
            <EmptyState compact icon="calendar" title="No settlements yet" message="Each weekly settlement you take part in appears here with its reference." />
          ) : (
            <View style={styles.skeletons}>
              {history.map((item) => (
                <View key={item.id} style={styles.historyRow}>
                  <View style={styles.historyText}>
                    <Text style={styles.historyTitle} numberOfLines={1}>
                      Week {formatDate(item.period_start)} – {formatDate(item.period_end)}
                    </Text>
                    <Text style={styles.meta} numberOfLines={2}>
                      {item.units} verified {item.units === 1 ? 'ad' : 'ads'}
                      {item.credited_at ? ` · ${formatDateTime(item.credited_at)}` : ''}
                      {item.reference ? ` · Ref ${item.reference}` : ''}
                    </Text>
                    {item.status === 'below_minimum' ? <Text style={styles.meta}>Below the minimum payout, so not credited.</Text> : null}
                    {(item.adjustments ?? []).map((adjustment) => (
                      <Text key={adjustment.reference} style={styles.meta}>
                        Adjusted {adjustment.coins.startsWith('-') ? '' : '+'}
                        {formatCoins(adjustment.coins)} · {adjustment.reason} · Ref {adjustment.reference}
                      </Text>
                    ))}
                  </View>
                  <View style={styles.historyAmount}>
                    <CoinAmount value={item.coins} size={14} textStyle={[styles.campaignReward, item.status === 'credited' || item.status === 'adjusted' ? styles.credited : styles.pending]} />
                    <StatusChip label={HISTORY_STATUS[item.status].label} tone={HISTORY_STATUS[item.status].tone} />
                  </View>
                </View>
              ))}
              {historyPage.current < historyPage.last ? (
                <Button label="Load more" variant="secondary" size="sm" loading={historyLoading} onPress={() => loadHistory(historyPage.current + 1)} />
              ) : null}
            </View>
          )}
        </View>
      </ScrollView>

      <Sheet visible={helpOpen} onClose={() => setHelpOpen(false)} title="How Ads Earning works">
        <Text style={styles.body}>
          Watch eligible ads and earn coins when your activity qualifies for rewards. Your rewards are calculated from verified advertising revenue and credited to your Deposit Wallet according to Mini
          Max&apos;s reward rules.
        </Text>
        <Text style={styles.body}>
          • Only complete rewarded ads on this screen count, and only once the ad network confirms them.{'\n'}• There&apos;s a daily limit on counted ads.{'\n'}• Each week, the players&apos; share of the
          verified revenue from these ads is split by verified ads watched, then credited at the weekly settlement.{'\n'}• Turning Ads Earning off never removes what you already earned.
        </Text>
        <Button label="Got it" onPress={() => setHelpOpen(false)} />
      </Sheet>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.xs },
  column: { gap: SPACE.md },
  card: { ...glassPanel(c, 'surface2'), borderRadius: RADIUS.xl, padding: SPACE.md, gap: SPACE.sm },
  helpButton: { width: SIZE.touch, height: SIZE.touch, alignItems: 'center', justifyContent: 'center' },
  statusHead: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm },
  statusText: { flex: 1, minWidth: 0, gap: SPACE.xxs },
  switchWrap: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  cardTitle: { ...TYPE.section, color: c.fg },
  body: { ...TYPE.body, color: c.fg2 },
  meta: { ...TYPE.meta, color: c.fg3 },
  eligibleLine: { ...TYPE.meta, color: s.success, fontWeight: '600' },
  rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.xs, flexWrap: 'wrap' },
  progressRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  progressValue: { ...TYPE.cardTitle, color: c.fg },
  progressOf: { ...TYPE.cardTitle, color: c.fg3 },
  progressPct: { ...TYPE.meta, color: c.fg3, marginLeft: 'auto' },
  tiles: { flexDirection: 'row', gap: SPACE.xs },
  tile: { flex: 1, minWidth: 0, backgroundColor: c.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: c.border, padding: SPACE.sm, gap: 2 },
  tileLabel: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.4 },
  tileValue: { ...TYPE.cardTitle, color: c.fg, flexShrink: 1 },
  tileHint: { ...TYPE.meta, color: c.fg3 },
  pending: { color: s.warning },
  credited: { color: s.success },
  campaign: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, backgroundColor: c.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: c.border, padding: SPACE.sm },
  campaignText: { flex: 1, minWidth: 0, gap: 2 },
  campaignName: { ...TYPE.bodyStrong, color: c.fg },
  campaignReward: { ...TYPE.bodyStrong, color: c.fg },
  sectionTitle: { ...TYPE.section, color: c.fg, marginTop: SPACE.xs },
  skeletons: { gap: SPACE.xs },
  historyRow: { ...glassPanel(c), flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, borderRadius: RADIUS.md, padding: SPACE.sm },
  historyText: { flex: 1, minWidth: 0, gap: 2 },
  historyTitle: { ...TYPE.bodyStrong, color: c.fg },
  historyAmount: { alignItems: 'flex-end', gap: SPACE.xxs },
}));
