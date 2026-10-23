import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, FlatList, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '../src/api/client';
import { fetchDeposit, fetchDeposits, requestDeposit } from '../src/api/deposits';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { Button } from '../src/components/Button';
import { CoinAmount } from '../src/components/CoinAmount';
import { Notice } from '../src/components/Notice';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { StatusChip, type ChipTone } from '../src/components/StatusChip';
import { TextField } from '../src/components/TextField';
import { glassPanel } from '../src/theme/glass';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../src/theme/tokens';
import type { DepositOrder } from '../src/types';
import { formatDateTime } from '../src/utils/time';
import { useWallet } from '../src/wallet/WalletContext';

const STATUS_LABELS: Record<DepositOrder['status'], string> = {
  pending: 'Pending',
  success: 'Success',
  failed: 'Failed',
  expired: 'Expired',
};

const STATUS_TONES: Record<DepositOrder['status'], ChipTone> = {
  pending: 'warning',
  success: 'success',
  failed: 'danger',
  expired: 'neutral',
};

/**
 * Add coins over UPI: the amount opens the payment page in the in-app browser, and the
 * order's status (and the shared wallet) is re-checked when the player comes back. Coins are
 * credited only by the payment provider's confirmation on the server, never by this screen.
 */
export default function DepositScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const { refresh: refreshWallet } = useWallet();
  const [deposits, setDeposits] = useState<DepositOrder[] | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [amount, setAmount] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const pendingDepositId = useRef<number | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await fetchDeposits();
      setDeposits(response.data);
      setLoadFailed(false);
    } catch {
      setLoadFailed(true);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', async (state) => {
      if (state === 'active' && pendingDepositId.current !== null) {
        await fetchDeposit(pendingDepositId.current).catch(() => null);
        void refreshWallet({ force: true });
        await load();
      }
    });

    return () => subscription.remove();
  }, [load, refreshWallet]);

  async function handleDeposit() {
    if (submitting) return;
    setError(null);
    setSubmitting(true);
    try {
      const response = await requestDeposit(amount);
      setAmount('');
      if (response.data.payment_url) {
        pendingDepositId.current = response.data.id;
        await WebBrowser.openBrowserAsync(response.data.payment_url);
        await fetchDeposit(response.data.id).catch(() => null);
        void refreshWallet({ force: true }); // a confirmed payment credits the wallet
      }
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "We couldn't start the deposit. Check your connection and try again.");
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = Number(amount) > 0;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <FlatList
        data={deposits ?? []}
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
              <TextField
                label="Amount (coins)"
                placeholder="How many coins?"
                keyboardType="decimal-pad"
                value={amount}
                onChangeText={(value) => {
                  setAmount(value.replace(/[^\d.]/g, ''));
                  setError(null);
                }}
                hint="You pay with any UPI app. Coins are added once the payment is confirmed."
              />
              {error ? <Notice tone="danger">{error}</Notice> : null}
              <Button label="Deposit via UPI" icon="deposit" onPress={handleDeposit} loading={submitting} disabled={!canSubmit} />
            </View>
            <Text style={styles.sectionTitle} accessibilityRole="header">
              Deposit history
            </Text>
          </View>
        }
        ItemSeparatorComponent={() => <View style={{ height: SPACE.xs }} />}
        renderItem={({ item }) => (
          <View style={[styles.row, { width: contentWidth }]}>
            <View style={styles.rowText}>
              <CoinAmount value={item.amount} size={16} suffix="coins" />
              <Text style={styles.rowMeta}>{formatDateTime(item.created_at)}</Text>
            </View>
            <StatusChip tone={STATUS_TONES[item.status]} label={STATUS_LABELS[item.status]} />
          </View>
        )}
        ListEmptyComponent={
          <View style={{ width: contentWidth }}>
            {loadFailed && deposits === null ? (
              <ErrorState compact title="Couldn't load your deposits" onRetry={() => load()} />
            ) : deposits === null ? (
              <Skeleton height={64} radius={RADIUS.md} />
            ) : (
              <EmptyState compact icon="deposit" title="No deposits yet" message="Your UPI deposits and their status appear here." />
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
  sectionTitle: { ...TYPE.section, color: c.fg },
  // The text column shrinks; the status chip keeps its full width.
  row: { ...glassPanel(c), flexDirection: 'row', alignItems: 'flex-start', gap: SPACE.sm, borderRadius: RADIUS.md, padding: SPACE.sm },
  rowText: { flex: 1, minWidth: 0, gap: 2 },
  rowMeta: { ...TYPE.meta, color: c.fg3 },
}));
