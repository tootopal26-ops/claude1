import { Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { FlatList, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { fetchTournament, fetchTournamentResults } from '../../../src/api/games';
import { useAuth } from '../../../src/auth/AuthContext';
import { AmbientGlow } from '../../../src/components/AmbientGlow';
import { Avatar, type AvatarRing } from '../../../src/components/avatar/Avatar';
import { CoinAmount } from '../../../src/components/CoinAmount';
import { EmptyState, ErrorState, Skeleton } from '../../../src/components/states/States';
import { glassPanel } from '../../../src/theme/glass';
import { makeStyles, useTheme } from '../../../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE, TYPE } from '../../../src/theme/tokens';
import type { Tournament, TournamentResultRow, TournamentResults } from '../../../src/types';
import { formatDateTime } from '../../../src/utils/time';

const RINGS: Record<number, AvatarRing> = { 1: 'first', 2: 'second', 3: 'third' };

/**
 * A finished tournament's published standings, best rank first: each team's player and
 * in-game names, kills and the prize the server awarded. Until staff publish results it says
 * they are pending — no provisional or invented standings are shown.
 */
export default function TournamentResultsScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { user } = useAuth();
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const [tournament, setTournament] = useState<Tournament | null>(null);
  const [results, setResults] = useState<TournamentResults | null>(null);
  const [failed, setFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const [tournamentResponse, resultsResponse] = await Promise.all([fetchTournament(id), fetchTournamentResults(id)]);
      setTournament(tournamentResponse.data);
      setResults(resultsResponse.data);
      setFailed(false);
    } catch {
      setFailed(true);
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  const header = (
    <View style={[styles.header, { width: contentWidth }]}>
      {tournament ? (
        <>
          <Text style={styles.title} accessibilityRole="header">
            {tournament.title}
          </Text>
          <Text style={styles.meta}>
            {tournament.status === 'cancelled' ? 'Cancelled' : 'Played'} {formatDateTime(tournament.scheduled_start_at)}
          </Text>
        </>
      ) : null}
    </View>
  );

  return (
    <View style={styles.screen}>
      <Stack.Screen options={{ title: 'Results' }} />
      <AmbientGlow />
      <FlatList
        data={results?.published ? results.results : []}
        keyExtractor={(item) => `${item.rank}-${item.user_id}`}
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}
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
        ListHeaderComponent={header}
        ItemSeparatorComponent={() => <View style={{ height: SPACE.xs }} />}
        renderItem={({ item }) => (
          <View style={{ width: contentWidth }}>
            <ResultRow row={item} isMe={item.user_id === user?.id} />
          </View>
        )}
        ListEmptyComponent={
          <View style={{ width: contentWidth }}>
            {failed && !results ? (
              <ErrorState title="Couldn't load the results" onRetry={() => load()} />
            ) : !results ? (
              <View style={styles.skeletons}>
                {[0, 1, 2, 3].map((index) => (
                  <Skeleton key={index} height={64} radius={RADIUS.md} />
                ))}
              </View>
            ) : tournament?.status === 'cancelled' ? (
              <EmptyState icon="info" title="No results" message="This tournament was cancelled, so it has no standings." />
            ) : (
              <EmptyState icon="clock" title="Results pending" message="Staff are verifying this match. The standings and prizes appear here once they are published." />
            )}
          </View>
        }
      />
    </View>
  );
}

function ResultRow({ row, isMe }: { row: TournamentResultRow; isMe: boolean }) {
  const styles = useStyles();
  const names = row.players.map((player) => player.in_game_name).join(', ');

  return (
    <View
      style={[styles.row, isMe && styles.mine]}
      accessibilityLabel={`Rank ${row.rank}, ${row.username}${isMe ? ' (you)' : ''}, ${row.kills} kills, prize ${row.prize_awarded} coins`}
    >
      <Text style={styles.rank}>{row.rank}</Text>
      <Avatar username={row.username} size={SIZE.avatarRow - 8} ring={RINGS[row.rank] ?? 'ranked'} />
      <View style={styles.text}>
        <Text style={styles.name} numberOfLines={1}>
          {row.username}
          {isMe ? <Text style={styles.you}> · you</Text> : null}
        </Text>
        <Text style={styles.sub} numberOfLines={1}>
          {names} · {row.kills} {row.kills === 1 ? 'kill' : 'kills'}
        </Text>
      </View>
      <CoinAmount value={row.prize_awarded} size={16} textStyle={styles.prize} style={styles.prizeRow} />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.xs },
  header: { gap: SPACE.xxs, marginBottom: SPACE.md },
  title: { ...TYPE.screenTitle, color: c.fg },
  meta: { ...TYPE.meta, color: c.fg3 },
  skeletons: { gap: SPACE.xs },
  row: { ...glassPanel(c), flexDirection: 'row', alignItems: 'center', gap: SPACE.xs, minHeight: 60, paddingVertical: SPACE.xxs, paddingLeft: SPACE.xs, paddingRight: SPACE.sm, borderRadius: RADIUS.md },
  mine: { backgroundColor: c.brandSoft, borderColor: c.ring },
  rank: { ...TYPE.bodyStrong, color: c.fg2, width: 28, textAlign: 'center' },
  text: { flex: 1, minWidth: 0 },
  name: { ...TYPE.bodyStrong, color: c.fg },
  you: { color: c.brandText },
  sub: { ...TYPE.meta, color: c.fg3 },
  prizeRow: { gap: 5 },
  prize: { ...TYPE.bodyStrong, fontSize: 15, color: c.fg },
}));
