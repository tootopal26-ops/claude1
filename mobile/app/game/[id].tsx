import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { Redirect, Stack, useLocalSearchParams } from 'expo-router';

import { fetchGames, fetchTournaments } from '../../src/api/games';
import { useAuth } from '../../src/auth/AuthContext';
import { TournamentCard } from '../../src/components/TournamentCard';
import { makeStyles, useTheme } from '../../src/theme/ThemeContext';
import type { Game, Tournament } from '../../src/types';

/**
 * The tournaments for one game card — the destination of tapping a card on the
 * home screen. Joining still happens on the existing tournament screen.
 */
export default function GameTournamentsScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const gameId = Number(id);
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const [game, setGame] = useState<Game | null>(null);
  const [tournaments, setTournaments] = useState<Tournament[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const [gamesResponse, tournamentsResponse] = await Promise.all([fetchGames(), fetchTournaments(gameId)]);
      setGame(gamesResponse.data.find((candidate) => candidate.id === gameId) ?? null);
      setTournaments(tournamentsResponse.data);
    } catch {
      setError("Couldn't load tournaments. Pull down to try again.");
    }
  }, [gameId]);

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
      <Stack.Screen options={{ title: game?.title ?? 'Game' }} />
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
                await load();
                setRefreshing(false);
              }}
            />
          }
          ListHeaderComponent={
            game?.description ? <Text style={styles.description}>{game.description}</Text> : undefined
          }
          ListEmptyComponent={
            <View style={styles.emptyBox}>
              <Text style={styles.empty}>{error ?? 'No tournaments for this game right now.'}</Text>
              {error && (
                <Pressable onPress={load} accessibilityRole="button" hitSlop={8}>
                  <Text style={styles.retry}>Try again</Text>
                </Pressable>
              )}
            </View>
          }
          renderItem={({ item }) => <TournamentCard item={item} />}
        />
      )}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  listContent: { padding: 16, gap: 0 },
  description: { color: c.fg2, fontSize: 14, lineHeight: 20, marginBottom: 14 },
  emptyBox: { alignItems: 'center', gap: 10, marginTop: 32 },
  empty: { color: c.fg2, textAlign: 'center' },
  retry: { color: c.brandText, fontSize: 14, fontWeight: '700' },
}));
