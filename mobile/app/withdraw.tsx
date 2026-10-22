import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { Redirect } from 'expo-router';

import { ApiError } from '../src/api/client';
import { fetchWallet } from '../src/api/wallet';
import { fetchWithdrawals, requestWithdrawal } from '../src/api/withdrawals';
import { useAuth } from '../src/auth/AuthContext';
import type { WithdrawalRequest } from '../src/types';

const STATUS_LABELS: Record<WithdrawalRequest['status'], string> = {
  pending: 'Pending review',
  approved: 'Approved',
  processing: 'Processing',
  paid: 'Paid',
  rejected: 'Rejected',
  failed: 'Failed',
};

const STATUS_COLORS: Record<WithdrawalRequest['status'], string> = {
  pending: '#FACC15',
  approved: '#8B5CF6',
  processing: '#8B5CF6',
  paid: '#34D399',
  rejected: '#F87171',
  failed: '#F87171',
};

export default function WithdrawScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const [withdrawableBalance, setWithdrawableBalance] = useState<string | null>(null);
  const [withdrawals, setWithdrawals] = useState<WithdrawalRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [amount, setAmount] = useState('');
  const [upiId, setUpiId] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const load = useCallback(async () => {
    setLoadError(null);
    try {
      const [walletResponse, withdrawalsResponse] = await Promise.all([fetchWallet(), fetchWithdrawals()]);
      setWithdrawableBalance(walletResponse.data.withdrawable_balance);
      setWithdrawals(withdrawalsResponse.data);
    } catch {
      setLoadError('Could not load your withdrawals. Pull to retry.');
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

  async function handleSubmit() {
    setError(null);
    setSubmitting(true);
    try {
      await requestWithdrawal(amount, upiId);
      setAmount('');
      setUpiId('');
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not submit your withdrawal request.');
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = Number(amount) > 0 && upiId.trim().length > 0;

  return (
    <View style={styles.container}>
      {loading ? (
        <ActivityIndicator style={styles.centered} />
      ) : (
        <FlatList
          data={withdrawals}
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
            <View>
              <Text style={styles.balanceLabel}>Withdrawable balance</Text>
              <Text style={styles.balanceValue}>{withdrawableBalance ?? '—'} coins</Text>

              <View style={styles.form}>
                <TextInput
                  style={styles.input}
                  placeholder="Amount"
                  keyboardType="decimal-pad"
                  value={amount}
                  onChangeText={setAmount}
                />
                <TextInput
                  style={styles.input}
                  placeholder="UPI ID (e.g. name@bank)"
                  autoCapitalize="none"
                  value={upiId}
                  onChangeText={setUpiId}
                />
                {error && <Text style={styles.error}>{error}</Text>}
                <Pressable
                  style={[styles.button, !canSubmit && styles.buttonDisabled]}
                  onPress={handleSubmit}
                  disabled={submitting || !canSubmit}
                >
                  {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Request withdrawal</Text>}
                </Pressable>
              </View>

              <Text style={styles.historyHeading}>Request history</Text>
            </View>
          }
          ListEmptyComponent={<Text style={styles.empty}>{loadError ?? 'No withdrawal requests yet.'}</Text>}
          renderItem={({ item }) => (
            <View style={styles.row}>
              <View>
                <Text style={styles.rowAmount}>{item.amount} coins</Text>
                <Text style={styles.rowDate}>{new Date(item.created_at).toLocaleString()}</Text>
                {item.rejection_reason && <Text style={styles.rowReason}>{item.rejection_reason}</Text>}
                {item.failure_reason && <Text style={styles.rowReason}>{item.failure_reason}</Text>}
              </View>
              <Text style={[styles.rowStatus, { color: STATUS_COLORS[item.status] }]}>{STATUS_LABELS[item.status]}</Text>
            </View>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#1a1025' },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  listContent: { padding: 16 },
  balanceLabel: { color: '#C4B5FD', fontSize: 13, textAlign: 'center' },
  balanceValue: { color: '#fff', fontSize: 28, fontWeight: '800', textAlign: 'center', marginBottom: 16 },
  form: { gap: 12, marginBottom: 24 },
  input: {
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: 12,
    paddingHorizontal: 16,
    paddingVertical: 12,
    color: '#fff',
  },
  button: {
    backgroundColor: '#8B5CF6',
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
  },
  buttonDisabled: { opacity: 0.5 },
  buttonText: { color: '#fff', fontWeight: '600', fontSize: 16 },
  error: { color: '#F87171' },
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
  rowAmount: { color: '#fff', fontSize: 15, fontWeight: '700' },
  rowDate: { color: '#8B7BA8', fontSize: 11, marginTop: 2 },
  rowReason: { color: '#8B7BA8', fontSize: 11, marginTop: 2, fontStyle: 'italic' },
  rowStatus: { fontSize: 13, fontWeight: '700' },
});
