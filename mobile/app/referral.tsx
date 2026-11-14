import { useFocusEffect } from 'expo-router';
import * as Clipboard from 'expo-clipboard';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, Share, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { fetchReferralHistory, fetchReferralMe } from '../src/api/referrals';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { CoinAmount } from '../src/components/CoinAmount';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { glassPanel } from '../src/theme/glass';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../src/theme/tokens';
import type { ReferralHistoryItem, ReferralMe } from '../src/api/referrals';
import { formatDate } from '../src/utils/time';

/**
 * Refer & Earn: the player's own code (always generated, see User::booted()), their stats,
 * and their referral history. The backend is the only authority on every number here —
 * nothing is computed or guessed on the device. Hidden entirely from navigation when the
 * referral system is off (see account.tsx), but this screen itself also falls back to a
 * plain "not available" state if reached directly while off.
 */
export default function ReferralScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const [me, setMe] = useState<ReferralMe | null>(null);
  const [history, setHistory] = useState<ReferralHistoryItem[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [copied, setCopied] = useState(false);

  const load = useCallback(async () => {
    try {
      const [meResponse, historyResponse] = await Promise.all([fetchReferralMe(), fetchReferralHistory()]);
      setMe(meResponse.data);
      setHistory(historyResponse.data);
      setFailed(false);
    } catch {
      setFailed(true);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load])
  );

  async function copyCode() {
    if (!me) return;
    await Clipboard.setStringAsync(me.referral_code);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  async function shareCode() {
    if (!me) return;
    await Share.share({ message: `Join Mini Max with my referral code ${me.referral_code} and get a signup bonus!` });
  }

  if (failed && !me) {
    return (
      <View style={styles.screen}>
        <AmbientGlow />
        <ErrorState title="Couldn't load your referral details" onRetry={load} />
      </View>
    );
  }

  if (me && !me.enabled) {
    return (
      <View style={styles.screen}>
        <AmbientGlow />
        <EmptyState icon="users" title="Referrals aren't open right now" message="Check back later — this feature isn't live yet." />
      </View>
    );
  }

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}
        refreshControl={
          <RefreshControl
            tintColor={colors.brandText}
            colors={[colors.brand]}
            progressBackgroundColor={colors.surface3}
            refreshing={refreshing}
            onRefresh={async () => {
              setRefreshing(true);
              await load();
              setRefreshing(false);
            }}
          />
        }
      >
        <View style={[styles.column, { width: contentWidth }]}>
          {!me ? (
            <Skeleton height={96} radius={RADIUS.xl} />
          ) : (
            <View style={styles.inviteCard}>
              <Text style={styles.inviteTitle}>INVITE FRIENDS &amp; EARN COINS</Text>
              <View style={styles.inviteRow}>
                <Text style={styles.inviteRowLabel}>You earn</Text>
                <View style={styles.inviteRowValue}>
                  <CoinAmount value={me.referrer_reward} size={16} />
                  <Text style={styles.inviteRowSuffix}>per successful referral</Text>
                </View>
              </View>
              <View style={styles.inviteRow}>
                <Text style={styles.inviteRowLabel}>Your friend gets</Text>
                <View style={styles.inviteRowValue}>
                  <CoinAmount value={me.new_player_reward} size={16} />
                </View>
              </View>
              <Text style={styles.inviteNote}>Both rewards are credited to your Deposit Wallet.</Text>
            </View>
          )}

          {!me ? (
            <Skeleton height={160} radius={RADIUS.xl} />
          ) : (
            <View style={styles.codeCard}>
              <Text style={styles.codeLabel}>Your referral code</Text>
              <Text style={styles.code} selectable>
                {me.referral_code}
              </Text>
              <View style={styles.codeActions}>
                <Pressable style={styles.codeActionButton} onPress={copyCode} accessibilityRole="button" accessibilityLabel="Copy referral code">
                  <Icon name={copied ? 'check' : 'copy'} size={16} color={colors.brandText} />
                  <Text style={styles.codeActionText}>{copied ? 'Copied' : 'Copy code'}</Text>
                </Pressable>
                <Pressable style={styles.codeActionButton} onPress={shareCode} accessibilityRole="button" accessibilityLabel="Share referral code">
                  <Icon name="share" size={16} color={colors.brandText} />
                  <Text style={styles.codeActionText}>Share</Text>
                </Pressable>
              </View>
            </View>
          )}

          {!me ? (
            <Skeleton height={80} radius={RADIUS.lg} />
          ) : (
            <View style={styles.statsRow}>
              <View style={styles.statCard}>
                <Text style={styles.statValue}>{me.successful_referrals}</Text>
                <Text style={styles.statLabel}>Successful referrals</Text>
              </View>
              <View style={styles.statCard}>
                <CoinAmount value={me.total_coins_earned} size={18} textStyle={styles.statValue} />
                <Text style={styles.statLabel}>Coins earned</Text>
              </View>
            </View>
          )}

          <Text style={styles.sectionTitle} accessibilityRole="header">
            Referral history
          </Text>
          {!history ? (
            <Skeleton height={120} radius={RADIUS.lg} />
          ) : history.length === 0 ? (
            <EmptyState icon="users" title="No referrals yet" message="Share your code — when someone joins with it, you'll see it here." />
          ) : (
            <View style={styles.panel}>
              {history.map((item, index) => (
                <View key={item.id} style={[styles.historyRow, index < history.length - 1 && styles.divider]}>
                  <View style={styles.historyText}>
                    <Text style={styles.historyUsername} numberOfLines={1}>
                      {item.referred_username ?? 'A new player'}
                    </Text>
                    <Text style={styles.historyDate}>{formatDate(item.created_at)}</Text>
                  </View>
                  <CoinAmount value={item.referrer_reward} size={16} />
                </View>
              ))}
            </View>
          )}
        </View>
      </ScrollView>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.md },
  column: { gap: SPACE.md },
  inviteCard: { ...glassPanel(c), borderRadius: RADIUS.xl, padding: SPACE.md, gap: SPACE.xs },
  inviteTitle: { ...TYPE.label, fontSize: 12, letterSpacing: 1.5, color: c.brandText },
  inviteRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.sm },
  inviteRowLabel: { ...TYPE.bodyStrong, color: c.fg },
  inviteRowValue: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  inviteRowSuffix: { ...TYPE.meta, color: c.fg3 },
  inviteNote: { ...TYPE.meta, color: c.fg3, marginTop: 2 },
  codeCard: { ...glassPanel(c), borderRadius: RADIUS.xl, padding: SPACE.lg, alignItems: 'center', gap: SPACE.xs },
  codeLabel: { ...TYPE.meta, color: c.fg3 },
  code: { ...TYPE.hero, fontSize: 28, letterSpacing: 2, color: c.fg },
  codeActions: { flexDirection: 'row', gap: SPACE.sm, marginTop: SPACE.xs },
  codeActionButton: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: SPACE.md, paddingVertical: SPACE.xs, borderRadius: RADIUS.md, backgroundColor: c.surface2, minHeight: 40 },
  codeActionText: { ...TYPE.bodyStrong, fontSize: 13, color: c.brandText },
  statsRow: { flexDirection: 'row', gap: SPACE.sm },
  statCard: { flex: 1, ...glassPanel(c), borderRadius: RADIUS.lg, padding: SPACE.md, alignItems: 'center', gap: 4 },
  statValue: { ...TYPE.hero, fontSize: 22, color: c.fg },
  statLabel: { ...TYPE.meta, color: c.fg3, textAlign: 'center' },
  sectionTitle: { ...TYPE.section, color: c.fg, marginTop: SPACE.xs },
  panel: { ...glassPanel(c), borderRadius: RADIUS.lg, overflow: 'hidden' },
  historyRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.sm, minHeight: 56, paddingHorizontal: SPACE.md },
  divider: { borderBottomWidth: 1, borderBottomColor: c.border },
  historyText: { flex: 1, minWidth: 0, gap: 2 },
  historyUsername: { ...TYPE.bodyStrong, color: c.fg },
  historyDate: { ...TYPE.meta, color: c.fg3 },
}));
