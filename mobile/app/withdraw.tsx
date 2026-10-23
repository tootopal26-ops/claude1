import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, Text, View } from 'react-native';
import { Redirect } from 'expo-router';

import { ApiError } from '../src/api/client';
import { fetchWallet } from '../src/api/wallet';
import { fetchWithdrawals, requestWithdrawal } from '../src/api/withdrawals';
import { useAuth } from '../src/auth/AuthContext';
import { Button } from '../src/components/Button';
import { CoinAmount } from '../src/components/CoinAmount';
import { Notice } from '../src/components/Notice';
import { StatusChip, type ChipTone } from '../src/components/StatusChip';
import { TextField } from '../src/components/TextField';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import type { WithdrawalRequest } from '../src/types';

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

export default function WithdrawScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
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
        <ActivityIndicator color={colors.brandText} />
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
        <ActivityIndicator style={styles.centered} color={colors.brandText} />
      ) : (
        <FlatList
          data={withdrawals}
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
            <View>
              <Text style={styles.balanceLabel}>Withdrawable balance</Text>
              <CoinAmount value={withdrawableBalance} size={28} suffix="coins" style={styles.balanceRow} textStyle={styles.balanceValue} />

              <View style={styles.form}>
                <TextField
                  label="Amount"
                  placeholder="Amount"
                  keyboardType="decimal-pad"
                  value={amount}
                  onChangeText={setAmount}
                />
                <TextField
                  label="UPI ID"
                  placeholder="UPI ID (e.g. name@bank)"
                  autoCapitalize="none"
                  value={upiId}
                  onChangeText={setUpiId}
                />
                {error && <Notice tone="danger">{error}</Notice>}
                <Button label="Request withdrawal" onPress={handleSubmit} loading={submitting} disabled={!canSubmit} />
              </View>

              <Text style={styles.historyHeading}>Request history</Text>
            </View>
          }
          ListEmptyComponent={<Text style={styles.empty}>{loadError ?? 'No withdrawal requests yet.'}</Text>}
          renderItem={({ item }) => (
            <View style={styles.row}>
              <View>
                <CoinAmount value={item.amount} size={16} suffix="coins" />
                <Text style={styles.rowDate}>{new Date(item.created_at).toLocaleString()}</Text>
                {item.rejection_reason && <Text style={styles.rowReason}>{item.rejection_reason}</Text>}
                {item.failure_reason && <Text style={styles.rowReason}>{item.failure_reason}</Text>}
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
  balanceLabel: { color: c.fg2, fontSize: 13, textAlign: 'center' },
  balanceRow: { justifyContent: 'center', marginTop: 4, marginBottom: 16, gap: 8 },
  balanceValue: { color: c.fg, fontSize: 28, fontWeight: '800' },
  form: { gap: 12, marginBottom: 24 },
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
  rowDate: { color: c.fg3, fontSize: 11, marginTop: 2 },
  rowReason: { color: c.fg3, fontSize: 11, marginTop: 2, fontStyle: 'italic' },
}));
