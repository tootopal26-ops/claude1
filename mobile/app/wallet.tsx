import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AdSlot } from '../src/ads/AdSlot';
import { fetchWalletLedger } from '../src/api/wallet';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { Button } from '../src/components/Button';
import { CoinAmount } from '../src/components/CoinAmount';
import { CoinAsset } from '../src/components/CoinAsset';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { glassPanel } from '../src/theme/glass';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../src/theme/tokens';
import type { WalletLedgerEntry } from '../src/types';
import { formatCoins } from '../src/utils/coins';
import { pushOnce } from '../src/utils/navigation';
import { formatDateTime } from '../src/utils/time';
import { useRefreshWalletOnFocus, useWallet } from '../src/wallet/WalletContext';

const LEDGER_TYPE_LABELS: Record<string, string> = {
  deposit: 'Deposit',
  winning: 'Tournament winnings',
  entry_fee: 'Tournament entry fee',
  refund: 'Refund',
  admin_adjustment: 'Adjustment by Mini Max',
  section_unlock: 'Section unlock',
  result_correction: 'Tournament prize',
  withdrawal_reserve: 'Withdrawal requested',
  withdrawal_release: 'Withdrawal returned',
  ad_reward: 'Ad reward',
  daily_login_reward: 'Daily login reward',
  ads_earning_reward: 'Ads Earning reward',
  ad_campaign_reward: 'Ad reward',
};

/**
 * The wallet: the shared balance (the same figure Home shows, from the one wallet the server
 * keeps — split into deposit and withdrawable winnings), Add coins / Withdraw, and the
 * transaction history. A failed load shows a retry instead of spinning forever, and a
 * failed refresh keeps the last balance with a note that it may be out of date.
 */
export default function WalletScreen() {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const { wallet, loading: walletLoading, failed: walletFailed, refreshing: walletRefreshing, refresh: refreshWallet } = useWallet();
  useRefreshWalletOnFocus();
  const [entries, setEntries] = useState<WalletLedgerEntry[] | null>(null);
  const [ledgerFailed, setLedgerFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const loadLedger = useCallback(async () => {
    try {
      const response = await fetchWalletLedger();
      setEntries(response.data);
      setLedgerFailed(false);
    } catch {
      setLedgerFailed(true);
    }
  }, []);

  useEffect(() => {
    void loadLedger();
  }, [loadLedger]);

  const onRefresh = async () => {
    setRefreshing(true);
    await Promise.all([refreshWallet({ force: true }), loadLedger()]);
    setRefreshing(false);
  };

  const summary = (
    <View style={[styles.header, { width: contentWidth }]}>
      {walletLoading ? (
        <Skeleton height={176} radius={RADIUS.xl} />
      ) : !wallet ? (
        <View style={styles.balanceCard}>
          <ErrorState compact title="Couldn't load your balance" onRetry={() => refreshWallet({ force: true })} />
        </View>
      ) : (
        <View style={styles.balanceCard}>
          <View style={styles.totalHead}>
            <Text style={styles.totalLabel}>Total balance</Text>
            {walletRefreshing ? <ActivityIndicator size="small" color={colors.fg3} /> : null}
          </View>
          <View style={styles.totalRow}>
            <CoinAsset size={30} />
            <Text style={styles.total} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.5}>
              {formatCoins(wallet.total_balance)}
            </Text>
          </View>
          {walletFailed ? <Text style={[styles.stale, { color: semanticColors.warning }]}>Couldn&apos;t refresh — this may be out of date.</Text> : null}
          <View style={styles.tiles}>
            <View style={styles.tile}>
              <Text style={styles.tileLabel} numberOfLines={1}>
                Deposit
              </Text>
              <CoinAmount value={wallet.deposit_balance} size={16} textStyle={styles.tileValue} style={styles.tileAmount} />
              <Text style={styles.tileHint} numberOfLines={2}>
                For entry fees
              </Text>
            </View>
            <View style={styles.tile}>
              <Text style={styles.tileLabel} numberOfLines={1}>
                Winnings
              </Text>
              <CoinAmount value={wallet.withdrawable_balance} size={16} textStyle={[styles.tileValue, { color: semanticColors.success }]} style={styles.tileAmount} />
              <Text style={styles.tileHint} numberOfLines={2}>
                Withdrawable
              </Text>
            </View>
          </View>
          <View style={styles.actions}>
            <Button label="Add coins" artwork="deposit" variant="success" onPress={() => pushOnce('/deposit')} style={styles.action} />
            <Button label="Withdraw" artwork="withdraw" variant="danger" onPress={() => pushOnce('/withdraw')} style={styles.action} />
          </View>
        </View>
      )}
      <Text style={styles.sectionTitle} accessibilityRole="header">
        Transaction history
      </Text>
    </View>
  );

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <FlatList
        data={entries ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}
        refreshControl={<RefreshControl tintColor={colors.brandText} colors={[colors.brand]} progressBackgroundColor={colors.surface3} refreshing={refreshing} onRefresh={onRefresh} />}
        ListHeaderComponent={summary}
        ItemSeparatorComponent={() => <View style={styles.separator} />}
        ListFooterComponent={<AdSlot placement="wallet_profile" style={[styles.footerAd, { width: contentWidth }]} />}
        renderItem={({ item }) => <LedgerRow entry={item} width={contentWidth} />}
        ListEmptyComponent={
          <View style={{ width: contentWidth }}>
            {ledgerFailed && entries === null ? (
              <ErrorState compact title="Couldn't load your transactions" onRetry={() => loadLedger()} />
            ) : entries === null ? (
              <View style={styles.skeletons}>
                {[0, 1, 2].map((index) => (
                  <Skeleton key={index} height={56} radius={RADIUS.md} />
                ))}
              </View>
            ) : (
              <EmptyState compact icon="wallet" title="No transactions yet" message="Deposits, entry fees, winnings and refunds appear here." />
            )}
          </View>
        }
      />
    </View>
  );
}

function LedgerRow({ entry, width }: { entry: WalletLedgerEntry; width: number }) {
  const styles = useStyles();
  const { semanticColors } = useTheme();
  const isCredit = !entry.amount.startsWith('-');
  const label = LEDGER_TYPE_LABELS[entry.type] ?? entry.type;

  return (
    <View style={[styles.row, { width }]}>
      <View style={styles.rowText}>
        <Text style={styles.rowType} numberOfLines={1}>
          {label}
        </Text>
        <Text style={styles.rowMeta} numberOfLines={1}>
          {formatDateTime(entry.created_at)} · {entry.balance === 'winnings' ? 'Winnings' : 'Deposit'}
        </Text>
        {entry.reason && entry.reason !== label ? (
          <Text style={styles.rowMeta} numberOfLines={2}>
            {entry.reason}
          </Text>
        ) : null}
      </View>
      <CoinAmount
        value={`${isCredit ? '+' : ''}${entry.amount}`}
        size={14}
        textStyle={[styles.rowAmount, { color: isCredit ? semanticColors.success : semanticColors.danger }]}
        style={styles.rowAmountWrap}
      />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.xs },
  header: { gap: SPACE.lg, marginBottom: SPACE.sm },
  balanceCard: { ...glassPanel(c, 'surface2'), borderRadius: RADIUS.xl, padding: SPACE.md, gap: SPACE.sm },
  totalHead: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  totalLabel: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.6 },
  totalRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  total: { fontSize: 34, lineHeight: 40, fontWeight: '800', color: c.fg, flexShrink: 1 },
  stale: { ...TYPE.meta, fontWeight: '600' },
  tiles: { flexDirection: 'row', gap: SPACE.xs },
  tile: { flex: 1, minWidth: 0, backgroundColor: c.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: c.border, padding: SPACE.sm, gap: 2 },
  tileLabel: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.4 },
  tileAmount: { gap: 5, flexShrink: 1 },
  tileValue: { ...TYPE.cardTitle, color: c.fg, flexShrink: 1 },
  tileHint: { ...TYPE.meta, color: c.fg3 },
  actions: { flexDirection: 'row', gap: SPACE.xs, marginTop: SPACE.xxs },
  action: { flex: 1 },
  sectionTitle: { ...TYPE.section, color: c.fg },
  skeletons: { gap: SPACE.xs },
  separator: { height: SPACE.xs },
  footerAd: { marginTop: SPACE.lg },
  row: { ...glassPanel(c), flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, borderRadius: RADIUS.md, paddingHorizontal: SPACE.sm, paddingVertical: SPACE.sm },
  rowText: { flex: 1, minWidth: 0, gap: 2 },
  rowType: { ...TYPE.bodyStrong, color: c.fg },
  rowMeta: { ...TYPE.meta, color: c.fg3 },
  rowAmountWrap: { gap: 4, flexShrink: 0 },
  rowAmount: { ...TYPE.bodyStrong, fontSize: 15 },
}));
