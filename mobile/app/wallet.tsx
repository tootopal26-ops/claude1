import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, Text, View } from 'react-native';
import { Link, Redirect } from 'expo-router';

import { fetchWallet, fetchWalletLedger } from '../src/api/wallet';
import { useAuth } from '../src/auth/AuthContext';
import { Button } from '../src/components/Button';
import { CoinAmount } from '../src/components/CoinAmount';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import type { Wallet, WalletLedgerEntry } from '../src/types';

const LEDGER_TYPE_LABELS: Record<string, string> = {
  deposit: 'Deposit',
  winning: 'Tournament winnings',
  entry_fee: 'Tournament entry fee',
  refund: 'Refund',
  admin_adjustment: 'Admin adjustment',
};

export default function WalletScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [entries, setEntries] = useState<WalletLedgerEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const [walletResponse, ledgerResponse] = await Promise.all([fetchWallet(), fetchWalletLedger()]);
      setWallet(walletResponse.data);
      setEntries(ledgerResponse.data);
    } catch {
      setError('Could not load your wallet. Pull to retry.');
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    (async () => {
      setLoading(true);
      await load();
      setLoading(false);
    })();
  }, [user, load]);

  if (authLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  if (!user) {
    return <Redirect href="/login" />;
  }

  return (
    <View style={styles.container}>
      {loading || !wallet ? (
        <ActivityIndicator style={styles.centered} color={colors.brandText} />
      ) : (
        <FlatList
          data={entries}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.listContent}
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
          ListHeaderComponent={
            <View style={styles.summary}>
              <Text style={styles.totalLabel}>Total balance</Text>
              <CoinAmount value={wallet.total_balance} size={30} suffix="coins" style={styles.totalRow} textStyle={styles.totalValue} />

              <View style={styles.breakdownRow}>
                <View style={styles.breakdownItem}>
                  <Text style={styles.breakdownLabel}>Deposit</Text>
                  <CoinAmount value={wallet.deposit_balance} size={18} textStyle={styles.breakdownValue} />
                </View>
                <View style={styles.breakdownItem}>
                  <Text style={styles.breakdownLabel}>Winnings (withdrawable)</Text>
                  <CoinAmount value={wallet.withdrawable_balance} size={18} textStyle={[styles.breakdownValue, styles.winningsValue]} />
                </View>
              </View>

              <View style={styles.actionRow}>
                <Link href="/deposit" asChild>
                  <Button label="Add coins" style={styles.actionButton} />
                </Link>
                <Link href="/withdraw" asChild>
                  <Button label="Withdraw" variant="secondary" style={styles.actionButton} />
                </Link>
              </View>

              <Text style={styles.historyHeading}>Transaction history</Text>
            </View>
          }
          ListEmptyComponent={<Text style={styles.empty}>{error ?? 'No transactions yet.'}</Text>}
          renderItem={({ item }) => {
            const isCredit = !item.amount.startsWith('-');
            return (
              <View style={styles.row}>
                <View>
                  <Text style={styles.rowType}>{LEDGER_TYPE_LABELS[item.type] ?? item.type}</Text>
                  <Text style={styles.rowDate}>{new Date(item.created_at).toLocaleString()}</Text>
                  {item.reason && <Text style={styles.rowReason}>{item.reason}</Text>}
                </View>
                <CoinAmount
                  value={`${isCredit ? '+' : ''}${item.amount}`}
                  size={14}
                  textStyle={[styles.rowAmount, isCredit ? styles.credit : styles.debit]}
                />
              </View>
            );
          }}
        />
      )}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  container: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  listContent: { padding: 16 },
  summary: { marginBottom: 16 },
  totalLabel: { color: c.fg2, fontSize: 13, textAlign: 'center' },
  totalRow: { justifyContent: 'center', marginBottom: 16, marginTop: 4, gap: 8 },
  totalValue: { color: c.fg, fontSize: 32, fontWeight: '800' },
  breakdownRow: { flexDirection: 'row', gap: 12, marginBottom: 20 },
  breakdownItem: {
    flex: 1,
    backgroundColor: c.surface,
    borderRadius: 16,
    padding: 14,
    borderWidth: 1,
    borderColor: c.border,
  },
  breakdownLabel: { color: c.fg2, fontSize: 12, marginBottom: 6 },
  breakdownValue: { color: c.fg, fontSize: 18, fontWeight: '700' },
  winningsValue: { color: s.success },
  actionRow: { flexDirection: 'row', gap: 12, marginBottom: 20 },
  actionButton: { flex: 1 },
  historyHeading: { color: c.fg, fontSize: 15, fontWeight: '700', marginBottom: 8 },
  empty: { color: c.fg2, textAlign: 'center', marginTop: 32 },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: c.border,
  },
  rowType: { color: c.fg, fontSize: 14, fontWeight: '600' },
  rowDate: { color: c.fg3, fontSize: 11, marginTop: 2 },
  rowReason: { color: c.fg3, fontSize: 11, marginTop: 2, fontStyle: 'italic' },
  rowAmount: { fontSize: 15, fontWeight: '700' },
  credit: { color: s.success },
  debit: { color: s.danger },
}));
