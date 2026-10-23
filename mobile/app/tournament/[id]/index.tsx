import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Image, RefreshControl, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '../../../src/api/client';
import { fetchCredentials, fetchTournament } from '../../../src/api/games';
import { AmbientGlow } from '../../../src/components/AmbientGlow';
import { Button } from '../../../src/components/Button';
import { CoinAmount } from '../../../src/components/CoinAmount';
import { Notice } from '../../../src/components/Notice';
import { EmptyState, ErrorState, Skeleton } from '../../../src/components/states/States';
import { StatusChip } from '../../../src/components/StatusChip';
import { JoinSheet } from '../../../src/components/tournament/JoinSheet';
import { RoomCredentials } from '../../../src/components/tournament/RoomCredentials';
import { canJoin, isFree, isFull, MODE_LABELS, statusChip } from '../../../src/components/tournament/tournamentState';
import { glassPanel } from '../../../src/theme/glass';
import { Icon } from '../../../src/theme/icons';
import { makeStyles, useTheme } from '../../../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../../../src/theme/tokens';
import type { Tournament, TournamentCredentials } from '../../../src/types';
import { formatCoins } from '../../../src/utils/coins';
import { pushOnce } from '../../../src/utils/navigation';
import { formatDateTime } from '../../../src/utils/time';

type Credentials = { state: 'none' } | { state: 'waiting' } | { state: 'released'; credentials: TournamentCredentials } | { state: 'error' };

/**
 * One tournament: artwork, schedule, rewards and fill; the room ID and password (joined
 * players only, once released, with copy buttons); the player's own result; the rules; and
 * the join flow. Everything is re-read from the server on focus, on return to the app and on
 * pull-to-refresh, so slots, status and credentials stay current.
 */
export default function TournamentDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const [tournament, setTournament] = useState<Tournament | null>(null);
  const [loadState, setLoadState] = useState<'loading' | 'ready' | 'error' | 'missing'>('loading');
  const [credentials, setCredentials] = useState<Credentials>({ state: 'none' });
  const [joining, setJoining] = useState<Tournament | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [imageFailed, setImageFailed] = useState(false);
  const loaded = useRef(false);

  const loadCredentials = useCallback(async (current: Tournament) => {
    if (!current.already_joined || current.status === 'cancelled' || current.status === 'completed') {
      setCredentials({ state: 'none' });
      return;
    }
    if (!current.credentials_released) {
      setCredentials({ state: 'waiting' });
      return;
    }
    try {
      const response = await fetchCredentials(current.id);
      setCredentials({ state: 'released', credentials: response.data });
    } catch (error) {
      setCredentials(error instanceof ApiError && error.status === 404 ? { state: 'waiting' } : { state: 'error' });
    }
  }, []);

  const load = useCallback(async () => {
    try {
      const response = await fetchTournament(id);
      setTournament(response.data);
      setLoadState('ready');
      loaded.current = true;
      await loadCredentials(response.data);
    } catch (error) {
      if (!loaded.current) setLoadState(error instanceof ApiError && error.status === 404 ? 'missing' : 'error');
    }
  }, [id, loadCredentials]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') void load();
    });
    return () => subscription.remove();
  }, [load]);

  const title = tournament?.title ?? 'Tournament';

  if (loadState !== 'ready' || !tournament) {
    return (
      <View style={styles.screen}>
        <Stack.Screen options={{ title }} />
        <View style={[styles.column, styles.centerColumn, { width: contentWidth }]}>
          {loadState === 'loading' ? (
            <View style={styles.skeletons}>
              <Skeleton height={Math.round((contentWidth * 9) / 16)} radius={RADIUS.xl} />
              <Skeleton width="70%" height={22} />
              <Skeleton height={64} radius={RADIUS.md} />
              <Skeleton height={120} radius={RADIUS.lg} />
            </View>
          ) : loadState === 'missing' ? (
            <EmptyState icon="tournaments" title="Tournament not found" message="It may have been removed." />
          ) : (
            <ErrorState title="Couldn't load this tournament" onRetry={() => load()} />
          )}
        </View>
      </View>
    );
  }

  const chip = statusChip(tournament);
  const picture = tournament.game?.image_url ?? tournament.game?.thumbnail_url ?? null;
  const fill = tournament.total_slots > 0 ? Math.min(1, tournament.joined_slots / tournament.total_slots) : 0;
  const joinable = canJoin(tournament);

  return (
    <View style={styles.screen}>
      <Stack.Screen options={{ title }} />
      <AmbientGlow />
      <ScrollView
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
      >
        <View style={[styles.column, { width: contentWidth }]}>
          <View style={[styles.hero, { height: Math.round((contentWidth * 9) / 16) }]}>
            {picture && !imageFailed ? (
              <Image source={{ uri: picture }} style={styles.heroImage} resizeMode="cover" onError={() => setImageFailed(true)} accessibilityIgnoresInvertColors />
            ) : (
              <Icon name="tournaments" size={40} />
            )}
          </View>

          <View style={styles.titleBlock}>
            <Text style={styles.title} accessibilityRole="header">
              {tournament.title}
            </Text>
            <View style={styles.chips}>
              <StatusChip tone={chip.tone} label={chip.label} />
              <StatusChip tone="neutral" label={tournament.required_player_count === 1 ? MODE_LABELS[tournament.mode] : `${MODE_LABELS[tournament.mode]} · ${tournament.required_player_count} per team`} />
              {tournament.game ? <StatusChip tone="brand" label={tournament.game.title} /> : null}
            </View>
            <View style={styles.when}>
              <Icon name="calendar" size={16} />
              <Text style={styles.whenText}>{formatDateTime(tournament.scheduled_start_at)}</Text>
            </View>
          </View>

          <View style={styles.grid}>
            <Figure label="Prize pool" value={tournament.prize_pool} />
            <Figure label="Per kill" value={tournament.per_kill_reward} />
            <Figure label="Entry fee" value={isFree(tournament) ? null : tournament.entry_fee} />
          </View>

          <View style={styles.panel}>
            <View style={styles.slotsHead}>
              <Text style={styles.panelTitle}>Slots</Text>
              <Text style={styles.slotsText}>
                {tournament.joined_slots}/{tournament.total_slots} joined · {Math.max(0, tournament.total_slots - tournament.joined_slots)} left
              </Text>
            </View>
            <View style={styles.track} accessibilityRole="progressbar" accessibilityLabel="Slots filled" aria-valuemin={0} aria-valuemax={tournament.total_slots} aria-valuenow={tournament.joined_slots}>
              <View style={[styles.fill, { width: `${fill * 100}%` }]} />
            </View>
            {tournament.status === 'upcoming' && tournament.join_closes_at ? (
              <Text style={styles.metaText}>Joining closes {formatDateTime(tournament.join_closes_at)}</Text>
            ) : null}
          </View>

          {credentials.state === 'released' ? <RoomCredentials state="released" credentials={credentials.credentials} /> : null}
          {credentials.state === 'waiting' ? <RoomCredentials state="waiting" /> : null}
          {credentials.state === 'error' ? <RoomCredentials state="error" onRetry={() => load()} /> : null}

          {tournament.my_result ? (
            <View style={[styles.panel, { borderColor: semanticColors.rankGold }]}>
              <Text style={styles.panelTitle}>Your result</Text>
              <View style={styles.resultRow}>
                <ResultFigure label="Rank" value={`#${tournament.my_result.rank}`} />
                <ResultFigure label="Kills" value={String(tournament.my_result.kills)} />
                <View style={styles.resultFigure}>
                  <Text style={styles.figureLabel}>Prize</Text>
                  <CoinAmount value={tournament.my_result.prize_awarded} size={16} textStyle={styles.resultValue} />
                </View>
              </View>
            </View>
          ) : null}

          {tournament.status === 'completed' ? <Button label="View results" icon="trophy" variant="secondary" onPress={() => pushOnce(`/tournament/${tournament.id}/results`)} /> : null}

          {tournament.description ? (
            <View style={styles.panel}>
              <Text style={styles.panelTitle}>About</Text>
              <Text style={styles.bodyText}>{tournament.description}</Text>
            </View>
          ) : null}

          {tournament.rules && tournament.rules.trim().length > 0 ? (
            <View style={styles.panel}>
              <Text style={styles.panelTitle}>Rules</Text>
              <Text style={styles.bodyText} selectable>
                {tournament.rules.trim()}
              </Text>
            </View>
          ) : null}

          {tournament.status === 'upcoming' && !tournament.already_joined ? (
            joinable ? (
              <Button
                label={isFree(tournament) ? 'Join for free' : `Join · ${formatCoins(tournament.entry_fee)} coins`}
                onPress={() => setJoining(tournament)}
                accessibilityLabel={isFree(tournament) ? 'Join for free' : `Join for ${formatCoins(tournament.entry_fee)} coins`}
              />
            ) : (
              <Notice tone="warning">{isFull(tournament) ? 'This tournament is full.' : 'Joining is closed for this tournament.'}</Notice>
            )
          ) : null}
          {tournament.status === 'upcoming' && tournament.already_joined ? <Notice tone="success">You are in this tournament.</Notice> : null}
          {tournament.status === 'cancelled' ? <Notice tone="danger">This tournament was cancelled. Any entry fee you paid was refunded to your wallet.</Notice> : null}
        </View>
      </ScrollView>
      <JoinSheet tournament={joining} onClose={() => setJoining(null)} onJoined={() => void load()} onDetailsScreen />
    </View>
  );
}

function Figure({ label, value }: { label: string; value: string | null }) {
  const styles = useStyles();
  const { semanticColors } = useTheme();

  return (
    <View style={styles.figure}>
      <Text style={styles.figureLabel}>{label}</Text>
      {value === null ? <Text style={[styles.figureValue, { color: semanticColors.success }]}>Free</Text> : <CoinAmount value={value} size={16} textStyle={styles.figureValue} />}
    </View>
  );
}

function ResultFigure({ label, value }: { label: string; value: string }) {
  const styles = useStyles();

  return (
    <View style={styles.resultFigure}>
      <Text style={styles.figureLabel}>{label}</Text>
      <Text style={styles.resultValue}>{value}</Text>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.xs },
  column: { gap: SPACE.md },
  centerColumn: { alignSelf: 'center', paddingTop: SPACE.xs },
  skeletons: { gap: SPACE.md },
  hero: { borderRadius: RADIUS.xl, overflow: 'hidden', backgroundColor: c.surface2, borderWidth: 1, borderColor: c.border, alignItems: 'center', justifyContent: 'center' },
  heroImage: { width: '100%', height: '100%' },
  titleBlock: { gap: SPACE.xs },
  title: { ...TYPE.hero, color: c.fg },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  when: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  whenText: { ...TYPE.body, color: c.fg2 },
  grid: { flexDirection: 'row', gap: SPACE.xs },
  figure: { ...glassPanel(c), flex: 1, minWidth: 0, borderRadius: RADIUS.md, padding: SPACE.sm, gap: SPACE.xxs },
  figureLabel: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.4 },
  figureValue: { ...TYPE.cardTitle, color: c.fg },
  panel: { ...glassPanel(c), borderRadius: RADIUS.lg, padding: SPACE.md, gap: SPACE.xs },
  panelTitle: { ...TYPE.cardTitle, color: c.fg },
  slotsHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.xs },
  slotsText: { ...TYPE.meta, color: c.fg2 },
  track: { height: 8, borderRadius: 4, backgroundColor: c.surface3, overflow: 'hidden' },
  fill: { height: 8, borderRadius: 4, backgroundColor: c.brand },
  metaText: { ...TYPE.meta, color: c.fg3 },
  bodyText: { ...TYPE.body, lineHeight: 22, color: c.fg2 },
  resultRow: { flexDirection: 'row', gap: SPACE.sm },
  resultFigure: { flex: 1, gap: SPACE.xxs },
  resultValue: { ...TYPE.section, color: c.fg },
}));
