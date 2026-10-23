import { router } from 'expo-router';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';

import { makeStyles, useTheme } from '../theme/ThemeContext';
import { Icon } from '../theme/icons';
import { formatCoins } from '../utils/coins';
import { useWallet } from '../wallet/WalletContext';
import { CoinAsset } from './CoinAsset';

function minutesAgo(updatedAt: number | null): string | null {
  if (updatedAt === null) return null;
  const minutes = Math.floor((Date.now() - updatedAt) / 60000);
  if (minutes < 1) return 'just now';
  return minutes === 1 ? '1 minute ago' : `${minutes} minutes ago`;
}

/**
 * The home screen's balance card. The big number is the total the player can
 * spend on entries (deposit + winnings); the line under it names both parts and
 * says which one can be withdrawn, so the total is never mistaken for cash-out
 * money. States: loading placeholder, recoverable error, a refreshing hint that
 * keeps the old number, and an explicit "out of date" notice when a refresh failed.
 */
export function WalletCard() {
  const { colors } = useTheme();
  const styles = useStyles();
  const { wallet, loading, refreshing, failed, updatedAt, refresh } = useWallet();

  if (loading) {
    return (
      <View style={styles.card} accessibilityRole="progressbar" accessibilityLabel="Loading your balance">
        <View style={[styles.tile, styles.skeletonTile]} />
        <View style={styles.body}>
          <View style={[styles.skeleton, { width: 90, height: 12 }]} />
          <View style={[styles.skeleton, { width: 140, height: 28, marginTop: 8 }]} />
        </View>
      </View>
    );
  }

  if (wallet === null) {
    return (
      <View style={styles.card}>
        <View style={styles.body}>
          <Text style={styles.label}>Total balance</Text>
          <Text style={styles.errorText}>We couldn&apos;t load your balance.</Text>
        </View>
        <Pressable
          onPress={() => refresh({ force: true })}
          accessibilityRole="button"
          accessibilityLabel="Retry loading your balance"
          style={styles.retry}
          hitSlop={8}
        >
          <Text style={styles.retryText}>Try again</Text>
        </Pressable>
      </View>
    );
  }

  const total = formatCoins(wallet.total_balance);
  const deposit = formatCoins(wallet.deposit_balance);
  const winnings = formatCoins(wallet.eligible_winnings_balance);
  const staleNote = failed ? `Couldn't refresh · last updated ${minutesAgo(updatedAt) ?? 'earlier'}` : null;

  return (
    <Pressable
      onPress={() => router.push('/wallet')}
      accessibilityRole="button"
      accessibilityLabel={`Wallet. Total balance ${total} coins: ${deposit} deposit and ${winnings} winnings, of which only winnings can be withdrawn.${staleNote ? ` ${staleNote}.` : ''} Opens your wallet.`}
      style={({ pressed }) => [styles.card, pressed && styles.cardPressed]}
    >
      <View style={styles.tile}>
        <CoinAsset size={34} />
      </View>
      <View style={styles.body}>
        <View style={styles.labelRow}>
          <Text style={styles.label}>Total balance</Text>
          {refreshing && <ActivityIndicator size="small" color={colors.fg3} style={styles.spinner} />}
        </View>
        <Text style={styles.total} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.6}>
          {total}
          <Text style={styles.unit}> coins</Text>
        </Text>
        <Text style={styles.breakdown} numberOfLines={2}>
          Deposit {deposit} · Winnings {winnings} (withdrawable)
        </Text>
        {staleNote && <Text style={styles.stale}>{staleNote}</Text>}
      </View>
      <Icon name="chevron-right" size={18} color={colors.fg3} />
    </Pressable>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  // A compact glass card: translucent surface and a hairline border (no backdrop blur,
  // so it renders identically on every Android version).
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    padding: 14,
    borderRadius: 18,
    backgroundColor: `${c.surface2}e6`,
    borderWidth: 1,
    borderColor: c.borderStrong,
    marginBottom: 20,
  },
  cardPressed: { backgroundColor: c.surface3 },
  tile: {
    width: 52,
    height: 52,
    borderRadius: 14,
    backgroundColor: c.brandSoft,
    borderWidth: 1,
    borderColor: c.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  skeletonTile: { backgroundColor: c.surface },
  skeleton: { borderRadius: 6, backgroundColor: c.surface3 },
  body: { flex: 1, minWidth: 0 },
  labelRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  label: { color: c.fg3, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.6 },
  spinner: { transform: [{ scale: 0.7 }] },
  total: { color: c.fg, fontSize: 30, fontWeight: '800', marginTop: 2 },
  unit: { color: c.fg3, fontSize: 14, fontWeight: '600' },
  breakdown: { color: c.fg2, fontSize: 12, marginTop: 2 },
  stale: { color: s.warning, fontSize: 12, fontWeight: '600', marginTop: 4 },
  errorText: { color: c.fg2, fontSize: 14, marginTop: 4 },
  retry: { paddingHorizontal: 14, paddingVertical: 10, borderRadius: 12, backgroundColor: c.brandSoft, borderWidth: 1, borderColor: c.borderStrong },
  retryText: { color: c.brandText, fontSize: 14, fontWeight: '700' },
}));
