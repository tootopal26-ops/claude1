import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { Link, Redirect, router } from 'expo-router';

import { fetchGames, fetchTournaments } from '../src/api/games';
import { fetchNotifications } from '../src/api/notifications';
import { useAuth } from '../src/auth/AuthContext';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { GameCard, GameCardSkeleton } from '../src/components/GameCard';
import { TournamentCard } from '../src/components/TournamentCard';
import { WalletCard } from '../src/components/WalletCard';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { useRefreshWalletOnFocus, useWallet } from '../src/wallet/WalletContext';
import type { Game, Tournament } from '../src/types';

export default function TournamentListScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const { refresh: refreshWallet } = useWallet();
  useRefreshWalletOnFocus();
  const [tournaments, setTournaments] = useState<Tournament[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [unreadCount, setUnreadCount] = useState(0);
  const [games, setGames] = useState<Game[]>([]);
  const [gamesState, setGamesState] = useState<'loading' | 'ready' | 'error'>('loading');

  const loadTournaments = useCallback(async () => {
    setError(null);
    try {
      const response = await fetchTournaments();
      setTournaments(response.data);
    } catch {
      setError('Could not load tournaments. Pull to retry.');
    }
  }, []);

  const loadGames = useCallback(async () => {
    setGamesState('loading');
    try {
      const response = await fetchGames();
      setGames(response.data);
      setGamesState('ready');
    } catch {
      setGamesState('error');
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    (async () => {
      setLoading(true);
      await Promise.all([loadTournaments(), loadGames()]);
      setLoading(false);
    })();
    fetchNotifications()
      .then((response) => setUnreadCount(response.meta.unread_count))
      .catch(() => {
        // Non-fatal: the badge just stays at its last known value.
      });
  }, [user, loadTournaments, loadGames]);

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
      <AmbientGlow />
      <View style={styles.header}>
        <Text style={styles.welcome}>Welcome, {user.username}</Text>
        <View style={styles.headerActions}>
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
          <Link href="/profile" asChild>
            <Pressable style={styles.iconButton} accessibilityRole="button" accessibilityLabel="Profile">
              <Icon name="user" size={20} color={colors.fg2} />
            </Pressable>
          </Link>
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
                await Promise.all([loadTournaments(), loadGames(), refreshWallet({ force: true })]);
                setRefreshing(false);
              }}
            />
          }
          ListHeaderComponent={
            <View>
              <WalletCard />
              <GamesSection games={games} state={gamesState} onRetry={loadGames} />
              <Text style={styles.sectionTitle} accessibilityRole="header">
                Tournaments
              </Text>
            </View>
          }
          ListEmptyComponent={
            <Text style={styles.empty}>{error ?? 'No tournaments available right now.'}</Text>
          }
          renderItem={({ item }) => <TournamentCard item={item} />}
        />
      )}
    </View>
  );
}

const GRID_GAP = 12;
const GRID_PADDING = 16;

/**
 * The admin-managed game cards, two per row. Card width follows the screen so
 * the 16:9 frames stay aligned on small and large phones alike.
 */
function GamesSection({ games, state, onRetry }: { games: Game[]; state: 'loading' | 'ready' | 'error'; onRetry: () => void }) {
  const styles = useStyles();
  const { width } = useWindowDimensions();
  const cardWidth = Math.floor((width - GRID_PADDING * 2 - GRID_GAP) / 2);

  return (
    <View style={styles.gamesSection}>
      <Text style={styles.sectionTitle} accessibilityRole="header">
        Games
      </Text>
      {state === 'loading' && (
        <View style={styles.grid}>
          {[0, 1, 2, 3].map((index) => (
            <GameCardSkeleton key={index} width={cardWidth} />
          ))}
        </View>
      )}
      {state === 'error' && (
        <View style={styles.gamesMessage}>
          <Text style={styles.gamesMessageText}>Couldn't load the games.</Text>
          <Pressable onPress={onRetry} accessibilityRole="button" accessibilityLabel="Retry loading games" hitSlop={8}>
            <Text style={styles.retry}>Try again</Text>
          </Pressable>
        </View>
      )}
      {state === 'ready' && games.length === 0 && (
        <View style={styles.gamesMessage}>
          <Text style={styles.gamesMessageText}>No games are available yet. Check back soon.</Text>
        </View>
      )}
      {state === 'ready' && games.length > 0 && (
        <View style={styles.grid}>
          {games.map((game) => (
            <GameCard key={game.id} game={game} width={cardWidth} onPress={() => router.push(`/game/${game.id}`)} />
          ))}
        </View>
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
  listContent: { padding: 16, gap: 12 },
  empty: { color: c.fg2, textAlign: 'center', marginTop: 32 },
  gamesSection: { marginBottom: 20 },
  sectionTitle: { color: c.fg, fontSize: 16, fontWeight: '700', marginBottom: 10 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: GRID_GAP },
  gamesMessage: {
    backgroundColor: c.surface,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: c.border,
    padding: 16,
    alignItems: 'center',
    gap: 8,
  },
  gamesMessageText: { color: c.fg2, fontSize: 13, textAlign: 'center' },
  retry: { color: c.brandText, fontSize: 14, fontWeight: '700' },
}));
