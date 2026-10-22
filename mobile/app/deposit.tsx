import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { Redirect } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';

import { ApiError } from '../src/api/client';
import { fetchDeposit, fetchDeposits, requestDeposit } from '../src/api/deposits';
import { useAuth } from '../src/auth/AuthContext';
import type { DepositOrder } from '../src/types';

const STATUS_LABELS: Record<DepositOrder['status'], string> = {
  pending: 'Pending',
  success: 'Success',
  failed: 'Failed',
  expired: 'Expired',
};

const STATUS_COLORS: Record<DepositOrder['status'], string> = {
  pending: '#FACC15',
  success: '#34D399',
  failed: '#F87171',
  expired: '#8B7BA8',
};

export default function DepositScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const [deposits, setDeposits] = useState<DepositOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [amount, setAmount] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const pendingDepositId = useRef<number | null>(null);

  const load = useCallback(async () => {
    setLoadError(null);
    try {
      const response = await fetchDeposits();
      setDeposits(response.data);
    } catch {
      setLoadError('Could not load your deposits. Pull to retry.');
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

  useEffect(() => {
    const subscription = AppState.addEventListener('change', async (state) => {
      if (state === 'active' && pendingDepositId.current !== null) {
        await fetchDeposit(pendingDepositId.current).catch(() => null);
        await load();
      }
    });

    return () => subscription.remove();
  }, [load]);

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

  async function handleDeposit() {
    setError(null);
    setSubmitting(true);
    try {
      const response = await requestDeposit(amount);
      setAmount('');
      if (response.data.payment_url) {
        pendingDepositId.current = response.data.id;
        await WebBrowser.openBrowserAsync(response.data.payment_url);
        await fetchDeposit(response.data.id).catch(() => null);
      }
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not start the deposit.');
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = Number(amount) > 0;

  return (
    <View style={styles.container}>
      {loading ? (
        <ActivityIndicator style={styles.centered} />
      ) : (
        <FlatList
          data={deposits}
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
            <View style={styles.form}>
              <Text style={styles.heading}>Add coins</Text>
              <TextInput
                style={styles.input}
                placeholder="Amount"
                keyboardType="decimal-pad"
                value={amount}
                onChangeText={setAmount}
              />
              {error && <Text style={styles.error}>{error}</Text>}
              <Pressable
                style={[styles.button, !canSubmit && styles.buttonDisabled]}
                onPress={handleDeposit}
                disabled={submitting || !canSubmit}
              >
                {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Deposit via UPI</Text>}
              </Pressable>

              <Text style={styles.historyHeading}>Deposit history</Text>
            </View>
          }
          ListEmptyComponent={<Text style={styles.empty}>{loadError ?? 'No deposits yet.'}</Text>}
          renderItem={({ item }) => (
            <View style={styles.row}>
              <View>
                <Text style={styles.rowAmount}>{item.amount} coins</Text>
                <Text style={styles.rowDate}>{new Date(item.created_at).toLocaleString()}</Text>
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
  form: { marginBottom: 24 },
  heading: { color: '#fff', fontSize: 18, fontWeight: '700', marginBottom: 12 },
  input: {
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: 12,
    paddingHorizontal: 16,
    paddingVertical: 12,
    color: '#fff',
    marginBottom: 12,
  },
  button: {
    backgroundColor: '#8B5CF6',
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
  },
  buttonDisabled: { opacity: 0.5 },
  buttonText: { color: '#fff', fontWeight: '600', fontSize: 16 },
  error: { color: '#F87171', marginBottom: 8 },
  historyHeading: { color: '#fff', fontSize: 15, fontWeight: '700', marginTop: 24, marginBottom: 8 },
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
  rowStatus: { fontSize: 13, fontWeight: '700' },
});
