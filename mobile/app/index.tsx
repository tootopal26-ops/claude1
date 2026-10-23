import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { Link, Redirect } from 'expo-router';

import { apiRequest } from '../src/api/client';
import { fetchNotifications } from '../src/api/notifications';
import { fetchWallet } from '../src/api/wallet';
import { useAuth } from '../src/auth/AuthContext';
import { CoinAmount } from '../src/components/CoinAmount';
import { StatusChip } from '../src/components/StatusChip';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import type { Tournament } from '../src/types';

export default function TournamentListScreen() {
  const { user, isLoading: authLoading, logout } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const [tournaments, setTournaments] = useState<Tournament[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [unreadCount, setUnreadCount] = useState(0);
  const [totalBalance, setTotalBalance] = useState<string | null>(null);

  const loadTournaments = useCallback(async () => {
    setError(null);
    try {
      const response = await apiRequest<{ data: Tournament[] }>('/tournaments');
      setTournaments(response.data);
    } catch {
      setError('Could not load tournaments. Pull to retry.');
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    (async () => {
      setLoading(true);
      await loadTournaments();
      setLoading(false);
    })();
    fetchNotifications()
      .then((response) => setUnreadCount(response.meta.unread_count))
      .catch(() => {
        // Non-fatal: the badge just stays at its last known value.
      });
    fetchWallet()
      .then((response) => setTotalBalance(response.data.total_balance))
      .catch(() => {
        // Non-fatal: the header just omits the balance until the next load.
      });
  }, [user, loadTournaments]);

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
      <View style={styles.header}>
        <Text style={styles.welcome}>Welcome, {user.username}</Text>
        <View style={styles.headerActions}>
          <Link href="/wallet" asChild>
            <Pressable style={styles.balanceChip} accessibilityRole="button" accessibilityLabel="Open wallet">
              <CoinAmount value={totalBalance} size={14} textStyle={styles.balanceText} />
            </Pressable>
          </Link>
          <Link href="/leaderboard" asChild>
            <Pressable style={styles.iconButton} accessibilityRole="button" accessibilityLabel="Leaderboard">
              <Icon name="leaderboard" size={20} color={colors.fg2} />
            </Pressable>
          </Link>
          <Link href="/notifications" asChild>
            <Pressable
              style={styles.iconButton}
              accessibilityRole="button"
              accessibilityLabel={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'}
            >
              <Icon name="notifications" size={20} color={colors.fg2} />
              {unreadCount > 0 && (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{unreadCount > 9 ? '9+' : unreadCount}</Text>
                </View>
              )}
            </Pressable>
          </Link>
          <Link href="/settings" asChild>
            <Pressable style={styles.iconButton} accessibilityRole="button" accessibilityLabel="Settings">
              <Icon name="presets" size={20} color={colors.fg2} />
            </Pressable>
          </Link>
          <Pressable onPress={() => logout()} accessibilityRole="button" hitSlop={8}>
            <Text style={styles.logout}>Log out</Text>
          </Pressable>
        </View>
      </View>

      {loading ? (
        <ActivityIndicator style={styles.centered} color={colors.brandText} />
      ) : (
        <FlatList
          data={tournaments}
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
                await loadTournaments();
                setRefreshing(false);
              }}
            />
          }
          ListEmptyComponent={
            <Text style={styles.empty}>{error ?? 'No tournaments available right now.'}</Text>
          }
          renderItem={({ item }) => (
            <Link href={`/tournament/${item.id}`} asChild>
              <Pressable style={({ pressed }) => [styles.card, pressed && styles.cardPressed]}>
                <Text style={styles.cardTitle}>{item.title}</Text>
                <Text style={styles.cardMeta}>
                  {item.mode.toUpperCase()} · {item.joined_slots}/{item.total_slots} joined
                </Text>
                <View style={styles.slotTrack}>
                  <View
                    style={[
                      styles.slotFill,
                      { width: `${item.total_slots > 0 ? Math.min(100, (item.joined_slots / item.total_slots) * 100) : 0}%` },
                    ]}
                  />
                </View>
                <View style={styles.cardFooter}>
                  {Number(item.entry_fee) === 0 ? (
                    <StatusChip tone="success" label="Free entry" />
                  ) : (
                    <View style={styles.feeRow}>
                      <Text style={styles.cardMeta}>Entry fee:</Text>
                      <CoinAmount value={item.entry_fee} suffix="coins" />
                    </View>
                  )}
                  {item.already_joined && <StatusChip tone="brand" label="You're in" />}
                </View>
              </Pressable>
            </Link>
          )}
        />
      )}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  container: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 10,
    backgroundColor: c.sidebar,
    borderBottomWidth: 1,
    borderBottomColor: c.border,
  },
  welcome: { color: c.fg, fontSize: 16, fontWeight: '600', flexShrink: 1, marginRight: 8 },
  headerActions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  balanceChip: {
    backgroundColor: c.brandSoft,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderWidth: 1,
    borderColor: c.borderStrong,
  },
  balanceText: { fontSize: 13 },
  iconButton: { position: 'relative', width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  badge: {
    position: 'absolute',
    top: 2,
    right: 0,
    backgroundColor: c.brand,
    borderRadius: 8,
    minWidth: 16,
    height: 16,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 3,
  },
  badgeText: { color: c.onBrand, fontSize: 9, fontWeight: '700' },
  logout: { color: s.danger, fontWeight: '600', paddingLeft: 4 },
  listContent: { padding: 16, gap: 12 },
  empty: { color: c.fg2, textAlign: 'center', marginTop: 32 },
  card: {
    backgroundColor: c.surface,
    borderRadius: 16,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: c.border,
  },
  cardPressed: { backgroundColor: c.surface2, borderColor: c.borderStrong },
  cardTitle: { color: c.fg, fontSize: 18, fontWeight: '700', marginBottom: 4 },
  cardMeta: { color: c.fg2, fontSize: 13 },
  slotTrack: { height: 4, borderRadius: 2, backgroundColor: c.surface3, marginTop: 10, overflow: 'hidden' },
  slotFill: { height: 4, borderRadius: 2, backgroundColor: c.brand },
  cardFooter: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 12, gap: 8 },
  feeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
}));
