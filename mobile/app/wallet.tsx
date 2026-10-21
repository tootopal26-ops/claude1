import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Link, Redirect } from 'expo-router';

import { fetchWallet, fetchWalletLedger } from '../src/api/wallet';
import { useAuth } from '../src/auth/AuthContext';
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
        <ActivityIndicator />
      </View>
    );
  }

  if (!user) {
    return <Redirect href="/login" />;
  }

  return (
    <View style={styles.container}>
      {loading || !wallet ? (
        <ActivityIndicator style={styles.centered} />
      ) : (
        <FlatList
          data={entries}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.listContent}
          refreshControl={
            <RefreshControl
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
              <Text style={styles.totalValue}>{wallet.total_balance} coins</Text>

              <View style={styles.breakdownRow}>
                <View style={styles.breakdownItem}>
                  <Text style={styles.breakdownLabel}>Deposit</Text>
                  <Text style={styles.breakdownValue}>{wallet.deposit_balance}</Text>
                </View>
                <View style={styles.breakdownItem}>
                  <Text style={styles.breakdownLabel}>Winnings (withdrawable)</Text>
                  <Text style={[styles.breakdownValue, styles.winningsValue]}>{wallet.withdrawable_balance}</Text>
                </View>
              </View>

              <Link href="/withdraw" asChild>
                <Pressable style={styles.withdrawButton}>
                  <Text style={styles.withdrawButtonText}>Withdraw winnings</Text>
                </Pressable>
              </Link>

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
                <Text style={[styles.rowAmount, isCredit ? styles.credit : styles.debit]}>
                  {isCredit ? '+' : ''}
                  {item.amount}
                </Text>
              </View>
            );
          }}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#1a1025' },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  listContent: { padding: 16 },
  summary: { marginBottom: 16 },
  totalLabel: { color: '#C4B5FD', fontSize: 13, textAlign: 'center' },
  totalValue: { color: '#fff', fontSize: 32, fontWeight: '800', textAlign: 'center', marginBottom: 16 },
  breakdownRow: { flexDirection: 'row', gap: 12, marginBottom: 20 },
  breakdownItem: {
    flex: 1,
    backgroundColor: 'rgba(255,255,255,0.06)',
    borderRadius: 16,
    padding: 14,
    borderWidth: 1,
    borderColor: 'rgba(196,181,253,0.15)',
  },
  breakdownLabel: { color: '#C4B5FD', fontSize: 12, marginBottom: 4 },
  breakdownValue: { color: '#fff', fontSize: 18, fontWeight: '700' },
  winningsValue: { color: '#34D399' },
  withdrawButton: {
    backgroundColor: '#8B5CF6',
    borderRadius: 12,
    paddingVertical: 12,
    alignItems: 'center',
    marginBottom: 20,
  },
  withdrawButtonText: { color: '#fff', fontWeight: '600', fontSize: 15 },
  historyHeading: { color: '#fff', fontSize: 15, fontWeight: '700', marginBottom: 8 },
  empty: { color: '#C4B5FD', textAlign: 'center', marginTop: 32 },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: 'rgba(196,181,253,0.1)',
  },
  rowType: { color: '#fff', fontSize: 14, fontWeight: '600' },
  rowDate: { color: '#8B7BA8', fontSize: 11, marginTop: 2 },
  rowReason: { color: '#8B7BA8', fontSize: 11, marginTop: 2, fontStyle: 'italic' },
  rowAmount: { fontSize: 15, fontWeight: '700' },
  credit: { color: '#34D399' },
  debit: { color: '#F87171' },
});
