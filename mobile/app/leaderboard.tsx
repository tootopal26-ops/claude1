import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Redirect } from 'expo-router';

import { fetchLeaderboard } from '../src/api/leaderboards';
import { useAuth } from '../src/auth/AuthContext';
import type { Leaderboard } from '../src/types';

const TYPES: Leaderboard['type'][] = ['daily', 'weekly', 'monthly'];
const TYPE_LABELS: Record<Leaderboard['type'], string> = {
  daily: 'Daily',
  weekly: 'Weekly',
  monthly: 'Monthly',
};

export default function LeaderboardScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const [type, setType] = useState<Leaderboard['type']>('daily');
  const [leaderboard, setLeaderboard] = useState<Leaderboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (nextType: Leaderboard['type']) => {
    setError(null);
    try {
      const response = await fetchLeaderboard(nextType);
      setLeaderboard(response.data);
    } catch {
      setError('Could not load the leaderboard. Pull to retry.');
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    (async () => {
      setLoading(true);
      await load(type);
      setLoading(false);
    })();
  }, [user, type, load]);

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

  const myEntryInTop = leaderboard?.entries.some((entry) => entry.username === user.username) ?? false;

  return (
    <View style={styles.container}>
      <View style={styles.tabRow}>
        {TYPES.map((t) => (
          <Pressable key={t} style={[styles.tab, type === t && styles.tabActive]} onPress={() => setType(t)}>
            <Text style={[styles.tabText, type === t && styles.tabTextActive]}>{TYPE_LABELS[t]}</Text>
          </Pressable>
        ))}
      </View>

      {loading ? (
        <ActivityIndicator style={styles.centered} />
      ) : !leaderboard ? (
        <Pressable style={styles.centered} onPress={() => load(type)}>
          <Text style={styles.empty}>{error ?? 'Could not load the leaderboard.'} Tap to retry.</Text>
        </Pressable>
      ) : (
        <FlatList
          data={leaderboard.entries}
          keyExtractor={(item) => String(item.rank)}
          contentContainerStyle={styles.listContent}
          refreshControl={
            <RefreshControl
              refreshing={refreshing}
              onRefresh={async () => {
                setRefreshing(true);
                await load(type);
                setRefreshing(false);
              }}
            />
          }
          ListHeaderComponent={
            <View style={styles.statusRow}>
              <Text style={styles.statusText}>
                {leaderboard.status === 'provisional' ? 'Live standings' : 'Final standings'}
              </Text>
            </View>
          }
          ListFooterComponent={
            leaderboard.my_entry && !myEntryInTop ? (
              <View style={[styles.row, styles.myRow]}>
                <Text style={styles.rank}>#{leaderboard.my_entry.rank}</Text>
                <View style={styles.playerInfo}>
                  <Text style={styles.username}>{leaderboard.my_entry.username} (you)</Text>
                  <Text style={styles.meta}>{leaderboard.my_entry.total_kills} kills · {leaderboard.my_entry.tournaments_played} tournaments</Text>
                </View>
                <Text style={styles.score}>{leaderboard.my_entry.score}</Text>
              </View>
            ) : undefined
          }
          ListEmptyComponent={<Text style={styles.empty}>No results yet for this period.</Text>}
          renderItem={({ item }) => (
            <View style={[styles.row, item.username === user.username && styles.myRow]}>
              <Text style={styles.rank}>#{item.rank}</Text>
              <View style={styles.playerInfo}>
                <Text style={styles.username}>
                  {item.username}
                  {item.username === user.username ? ' (you)' : ''}
                </Text>
                <Text style={styles.meta}>{item.total_kills} kills · {item.tournaments_played} tournaments</Text>
              </View>
              <Text style={styles.score}>{item.score}</Text>
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
  tabRow: { flexDirection: 'row', padding: 16, gap: 8 },
  tab: {
    flex: 1,
    paddingVertical: 10,
    borderRadius: 12,
    alignItems: 'center',
    backgroundColor: 'rgba(255,255,255,0.08)',
  },
  tabActive: { backgroundColor: '#8B5CF6' },
  tabText: { color: '#C4B5FD', fontWeight: '600' },
  tabTextActive: { color: '#fff' },
  listContent: { padding: 16, paddingTop: 0 },
  statusRow: { marginBottom: 12 },
  statusText: { color: '#FACC15', fontSize: 12, fontWeight: '700' },
  empty: { color: '#C4B5FD', textAlign: 'center', marginTop: 32 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: 'rgba(196,181,253,0.1)',
  },
  myRow: { backgroundColor: 'rgba(139,92,246,0.15)', borderRadius: 12, paddingHorizontal: 8 },
  rank: { color: '#C4B5FD', fontSize: 14, fontWeight: '700', width: 40 },
  playerInfo: { flex: 1 },
  username: { color: '#fff', fontSize: 14, fontWeight: '700' },
  meta: { color: '#8B7BA8', fontSize: 11, marginTop: 2 },
  score: { color: '#34D399', fontSize: 15, fontWeight: '700' },
});
