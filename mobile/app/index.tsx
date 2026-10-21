import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Link, Redirect } from 'expo-router';

import { apiRequest } from '../src/api/client';
import { fetchNotifications } from '../src/api/notifications';
import { fetchWallet } from '../src/api/wallet';
import { useAuth } from '../src/auth/AuthContext';
import type { Tournament } from '../src/types';

export default function TournamentListScreen() {
  const { user, isLoading: authLoading, logout } = useAuth();
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
        <ActivityIndicator />
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
            <Pressable style={styles.balanceChip}>
              <Text style={styles.balanceIcon}>🪙</Text>
              <Text style={styles.balanceText}>{totalBalance ?? '—'}</Text>
            </Pressable>
          </Link>
          <Link href="/leaderboard" asChild>
            <Pressable style={styles.bellButton}>
              <Text style={styles.bellIcon}>🏆</Text>
            </Pressable>
          </Link>
          <Link href="/notifications" asChild>
            <Pressable style={styles.bellButton}>
              <Text style={styles.bellIcon}>🔔</Text>
              {unreadCount > 0 && (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{unreadCount > 9 ? '9+' : unreadCount}</Text>
                </View>
              )}
            </Pressable>
          </Link>
          <Pressable onPress={() => logout()}>
            <Text style={styles.logout}>Log out</Text>
          </Pressable>
        </View>
      </View>

      {loading ? (
        <ActivityIndicator style={styles.centered} />
      ) : (
        <FlatList
          data={tournaments}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.listContent}
          refreshControl={
            <RefreshControl
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
              <Pressable style={styles.card}>
                <Text style={styles.cardTitle}>{item.title}</Text>
                <Text style={styles.cardMeta}>
                  {item.mode.toUpperCase()} · {item.joined_slots}/{item.total_slots} joined
                </Text>
                <Text style={styles.cardMeta}>
                  {Number(item.entry_fee) === 0 ? 'Free entry' : `Entry fee: ${item.entry_fee} coins`}
                </Text>
                {item.already_joined && <Text style={styles.joinedBadge}>You're in</Text>}
              </Pressable>
            </Link>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#1a1025' },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: 16,
  },
  welcome: { color: '#fff', fontSize: 16, fontWeight: '600' },
  headerActions: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  // 🪙 is a placeholder — swap for the owner-supplied coin PNG (CoinAsset
  // component) once it's provided; see the icon-system spec.
  balanceChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    backgroundColor: 'rgba(139,92,246,0.15)',
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderWidth: 1,
    borderColor: 'rgba(139,92,246,0.4)',
  },
  balanceIcon: { fontSize: 14 },
  balanceText: { color: '#fff', fontSize: 13, fontWeight: '700' },
  bellButton: { position: 'relative', padding: 4 },
  bellIcon: { fontSize: 20 },
  badge: {
    position: 'absolute',
    top: -2,
    right: -4,
    backgroundColor: '#8B5CF6',
    borderRadius: 8,
    minWidth: 16,
    height: 16,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 3,
  },
  badgeText: { color: '#fff', fontSize: 9, fontWeight: '700' },
  logout: { color: '#F87171' },
  listContent: { padding: 16, gap: 12 },
  empty: { color: '#C4B5FD', textAlign: 'center', marginTop: 32 },
  card: {
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: 16,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: 'rgba(196,181,253,0.2)',
  },
  cardTitle: { color: '#fff', fontSize: 18, fontWeight: '700', marginBottom: 4 },
  cardMeta: { color: '#C4B5FD', fontSize: 13, marginBottom: 2 },
  joinedBadge: { color: '#34D399', fontWeight: '600', marginTop: 6 },
});
