import { useCallback, useEffect, useState } from 'react';
import { FlatList, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '../src/api/client';
import { useAds } from '../src/ads/AdsContext';
import { attemptWithdrawalPlacement } from '../src/ads/api';
import { fetchWithdrawals, requestWithdrawal } from '../src/api/withdrawals';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { Button } from '../src/components/Button';
import { useSound } from '../src/sound/SoundContext';
import { CoinAmount } from '../src/components/CoinAmount';
import { Notice } from '../src/components/Notice';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { StatusChip, type ChipTone } from '../src/components/StatusChip';
import { TextField } from '../src/components/TextField';
import { glassPanel } from '../src/theme/glass';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../src/theme/tokens';
import type { WithdrawalRequest } from '../src/types';
import { formatDateTime } from '../src/utils/time';
import { useRefreshWalletOnFocus, useWallet } from '../src/wallet/WalletContext';

const STATUS_LABELS: Record<WithdrawalRequest['status'], string> = {
  pending: 'Pending review',
  approved: 'Approved',
  processing: 'Processing',
  paid: 'Paid',
  rejected: 'Rejected',
  failed: 'Failed',
};

const STATUS_TONES: Record<WithdrawalRequest['status'], ChipTone> = {
  pending: 'warning',
  approved: 'info',
  processing: 'info',
  paid: 'success',
  rejected: 'danger',
  failed: 'danger',
};

/**
 * Withdraw winnings to a UPI ID. The withdrawable figure is the one shared wallet's (the
 * server's); a request reserves the amount on the server and staff review it, so the
 * history shows each request's real status and any reason the server gives.
 */
export default function WithdrawScreen() {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const { wallet, loading: walletLoading, refresh: refreshWallet } = useWallet();
  useRefreshWalletOnFocus();
  const [withdrawals, setWithdrawals] = useState<WithdrawalRequest[] | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [amount, setAmount] = useState('');
  const [upiId, setUpiId] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const sound = useSound();

  const { placement, sdkReady, showInterstitial } = useAds();
  const placementLive = sdkReady && placement('withdrawal_success') !== null;

  const load = useCallback(async () => {
    try {
      const response = await fetchWithdrawals();
      setWithdrawals(response.data);
      setLoadFailed(false);
      // The optional post-payout ad: only for a withdrawal the server has marked paid, once
      // per withdrawal (the server records the attempt), and never instead of the status.
      const latestPaid = response.data.find((item) => item.status === 'paid');
      if (latestPaid && placementLive) {
        const attempt = await attemptWithdrawalPlacement(latestPaid.id).catch(() => null);
        if (attempt?.data.show) void showInterstitial('withdrawal_success');
      }
    } catch {
      setLoadFailed(true);
    }
  }, [placementLive, showInterstitial]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleSubmit() {
    if (submitting) return;
    setError(null);
    setSubmitting(true);
    try {
      await requestWithdrawal(amount, upiId);
      setAmount('');
      setUpiId('');
      // Restrained confirmation that the request was submitted — it is still only pending review,
      // so this is not a "paid" success sound.
      sound.playNotification();
      void refreshWallet({ force: true }); // the amount is now reserved from winnings
      await load();
    } catch (err) {
      sound.playError();
      setError(err instanceof ApiError ? err.message : "We couldn't submit your request. Check your connection and try again.");
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = Number(amount) > 0 && upiId.trim().length > 0;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <FlatList
        data={withdrawals ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}
        keyboardShouldPersistTaps="handled"
        refreshControl={
          <RefreshControl
            tintColor={colors.brandText}
            colors={[colors.brand]}
            progressBackgroundColor={colors.surface3}
            refreshing={refreshing}
            onRefresh={async () => {
              setRefreshing(true);
              await Promise.all([load(), refreshWallet({ force: true })]);
              setRefreshing(false);
            }}
          />
        }
        ListHeaderComponent={
          <View style={[styles.header, { width: contentWidth }]}>
            <View style={styles.card}>
              <Text style={styles.balanceLabel}>Withdrawable balance</Text>
              {walletLoading ? (
                <Skeleton width={160} height={32} />
              ) : (
                <CoinAmount value={wallet?.withdrawable_balance ?? null} size={26} textStyle={[styles.balance, { color: semanticColors.success }]} style={styles.balanceRow} />
              )}
              <Text style={styles.hint}>Only tournament winnings can be withdrawn; deposited coins are for entry fees.</Text>
              <TextField
                label="Amount (coins)"
                placeholder="How many coins?"
                keyboardType="decimal-pad"
                value={amount}
                onChangeText={(value) => {
                  setAmount(value.replace(/[^\d.]/g, ''));
                  setError(null);
                }}
              />
              <TextField
                label="UPI ID"
                placeholder="name@bank"
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
                value={upiId}
                onChangeText={(value) => {
                  setUpiId(value.trim());
                  setError(null);
                }}
              />
              {error ? <Notice tone="danger">{error}</Notice> : null}
              <Button label="Request withdrawal" artwork="withdraw" variant="danger" onPress={handleSubmit} loading={submitting} disabled={!canSubmit} />
            </View>
            <Text style={styles.sectionTitle} accessibilityRole="header">
              Request history
            </Text>
          </View>
        }
        ItemSeparatorComponent={() => <View style={{ height: SPACE.xs }} />}
        renderItem={({ item }) => (
          <View style={[styles.row, { width: contentWidth }]}>
            <View style={styles.rowText}>
              <CoinAmount value={item.amount} size={16} suffix="coins" />
              <Text style={styles.rowMeta} numberOfLines={1}>
                {formatDateTime(item.created_at)} · {item.upi_id}
              </Text>
              {item.rejection_reason ? <Text style={styles.rowReason}>{item.rejection_reason}</Text> : null}
              {item.failure_reason ? <Text style={styles.rowReason}>{item.failure_reason}</Text> : null}
            </View>
            <StatusChip tone={STATUS_TONES[item.status]} label={STATUS_LABELS[item.status]} />
          </View>
        )}
        ListEmptyComponent={
          <View style={{ width: contentWidth }}>
            {loadFailed && withdrawals === null ? (
              <ErrorState compact title="Couldn't load your requests" onRetry={() => load()} />
            ) : withdrawals === null ? (
              <Skeleton height={64} radius={RADIUS.md} />
            ) : (
              <EmptyState compact icon="withdrawal" title="No withdrawal requests yet" message="Requests and their review status appear here." />
            )}
          </View>
        }
      />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.xs },
  header: { gap: SPACE.lg, marginBottom: SPACE.sm },
  card: { ...glassPanel(c, 'surface2'), borderRadius: RADIUS.xl, padding: SPACE.md, gap: SPACE.md },
  balanceLabel: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.6, marginBottom: -SPACE.xs },
  balanceRow: { gap: SPACE.xs },
  balance: { fontSize: 28, lineHeight: 34, fontWeight: '800' },
  hint: { ...TYPE.meta, color: c.fg3, marginTop: -SPACE.xs },
  sectionTitle: { ...TYPE.section, color: c.fg },
  // The text column shrinks and wraps; the status chip keeps its full width.
  row: { ...glassPanel(c), flexDirection: 'row', alignItems: 'flex-start', gap: SPACE.sm, borderRadius: RADIUS.md, padding: SPACE.sm },
  rowText: { flex: 1, minWidth: 0, gap: 2 },
  rowMeta: { ...TYPE.meta, color: c.fg3 },
  rowReason: { ...TYPE.meta, color: c.fg2 },
}));
