import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, FlatList, RefreshControl, Text, View } from 'react-native';
import { Redirect } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';

import { ApiError } from '../src/api/client';
import { fetchDeposit, fetchDeposits, requestDeposit } from '../src/api/deposits';
import { useAuth } from '../src/auth/AuthContext';
import { Button } from '../src/components/Button';
import { CoinAmount } from '../src/components/CoinAmount';
import { Notice } from '../src/components/Notice';
import { StatusChip, type ChipTone } from '../src/components/StatusChip';
import { TextField } from '../src/components/TextField';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import type { DepositOrder } from '../src/types';

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

export default function DepositScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
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
        <ActivityIndicator color={colors.brandText} />
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
        <ActivityIndicator style={styles.centered} color={colors.brandText} />
      ) : (
        <FlatList
          data={deposits}
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
            <View style={styles.form}>
              <Text style={styles.heading}>Add coins</Text>
              <TextField
                label="Amount"
                placeholder="Amount"
                keyboardType="decimal-pad"
                value={amount}
                onChangeText={setAmount}
              />
              {error && <Notice tone="danger">{error}</Notice>}
              <Button label="Deposit via UPI" onPress={handleDeposit} loading={submitting} disabled={!canSubmit} />

              <Text style={styles.historyHeading}>Deposit history</Text>
            </View>
          }
          ListEmptyComponent={<Text style={styles.empty}>{loadError ?? 'No deposits yet.'}</Text>}
          renderItem={({ item }) => (
            <View style={styles.row}>
              <View>
                <CoinAmount value={item.amount} size={16} suffix="coins" />
                <Text style={styles.rowDate}>{new Date(item.created_at).toLocaleString()}</Text>
              </View>
              <StatusChip tone={STATUS_TONES[item.status]} label={STATUS_LABELS[item.status]} />
            </View>
          )}
        />
      )}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  listContent: { padding: 16 },
  form: { marginBottom: 24, gap: 12 },
  heading: { color: c.fg, fontSize: 18, fontWeight: '700' },
  historyHeading: { color: c.fg, fontSize: 15, fontWeight: '700', marginTop: 12 },
  empty: { color: c.fg2, textAlign: 'center', marginTop: 32 },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: c.border,
  },
  rowDate: { color: c.fg3, fontSize: 11, marginTop: 2 },
}));
