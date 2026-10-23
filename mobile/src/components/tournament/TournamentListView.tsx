import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Animated, FlatList, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { fetchTournaments } from '../../api/games';
import { DURATION, EASE } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { MAX_CONTENT_WIDTH, SPACE, TYPE } from '../../theme/tokens';
import type { Tournament, TournamentTab } from '../../types';
import { pushOnce } from '../../utils/navigation';
import { AmbientGlow } from '../AmbientGlow';
import { SegmentedControl } from '../controls/SegmentedControl';
import { EmptyState, ErrorState, Skeleton } from '../states/States';
import { JoinSheet } from './JoinSheet';
import { RulesSheet } from './RulesSheet';
import { TournamentCard } from './TournamentCard';

const TABS: { value: TournamentTab; label: string }[] = [
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'ongoing', label: 'Ongoing' },
  { value: 'completed', label: 'Completed' },
];

const EMPTY: Record<TournamentTab, { title: string; message: string }> = {
  upcoming: { title: 'No upcoming tournaments', message: 'New tournaments appear here as soon as they are scheduled.' },
  ongoing: { title: 'Nothing live right now', message: 'Tournaments show here while they are being played.' },
  completed: { title: 'No completed tournaments', message: 'Finished tournaments and their results appear here.' },
};

export function parseTab(value: unknown): TournamentTab {
  return value === 'ongoing' || value === 'completed' ? value : 'upcoming';
}

type Props = {
  /** Only this game's tournaments; all games when omitted. */
  gameId?: number;
  initialTab: TournamentTab;
  /** Shown above the tabs (for example the game's description). */
  intro?: string | null;
};

/**
 * The tournament list behind the Home shortcuts and each game card: Upcoming / Ongoing /
 * Completed tabs with the server's counts, one card per tournament, the rules sheet and the
 * join flow. Each tab is kept once loaded, so switching back is instant while it refreshes,
 * and the list reloads whenever the screen is shown again (for example after joining from
 * the details screen).
 */
export function TournamentListView({ gameId, initialTab, intro }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const [tab, setTab] = useState<TournamentTab>(initialTab);
  const [lists, setLists] = useState<Partial<Record<TournamentTab, Tournament[]>>>({});
  const [counts, setCounts] = useState<Record<TournamentTab, number> | null>(null);
  const [failed, setFailed] = useState<Partial<Record<TournamentTab, boolean>>>({});
  const [refreshing, setRefreshing] = useState(false);
  const [rulesFor, setRulesFor] = useState<Tournament | null>(null);
  const [joining, setJoining] = useState<Tournament | null>(null);
  const opacity = useRef(new Animated.Value(1)).current;
  const firstFocus = useRef(true);

  const load = useCallback(
    async (target: TournamentTab) => {
      try {
        const response = await fetchTournaments({ gameId, status: target });
        setLists((current) => ({ ...current, [target]: response.data }));
        setCounts(response.meta.counts);
        setFailed((current) => ({ ...current, [target]: false }));
      } catch {
        setFailed((current) => ({ ...current, [target]: true }));
      }
    },
    [gameId],
  );

  useEffect(() => {
    opacity.setValue(0.4);
    void load(tab).finally(() => {
      Animated.timing(opacity, { toValue: 1, duration: DURATION.card, easing: EASE.out, useNativeDriver: true }).start();
    });
  }, [tab, load, opacity]);

  // Coming back to this screen (after a join, or from details): refresh the visible tab.
  // The tab is read from a ref so switching tabs does not trigger a second fetch here.
  const tabRef = useRef(tab);
  tabRef.current = tab;
  useFocusEffect(
    useCallback(() => {
      if (firstFocus.current) {
        firstFocus.current = false;
        return;
      }
      void load(tabRef.current);
    }, [load]),
  );

  const list = lists[tab];
  const showError = failed[tab] && !list;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <FlatList
        data={list ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xl }]}
        refreshControl={
          <RefreshControl
            tintColor={colors.brandText}
            colors={[colors.brand]}
            progressBackgroundColor={colors.surface3}
            refreshing={refreshing}
            onRefresh={async () => {
              setRefreshing(true);
              await load(tab);
              setRefreshing(false);
            }}
          />
        }
        ListHeaderComponent={
          <View style={[styles.header, { width: contentWidth }]}>
            {intro ? <Text style={styles.intro}>{intro}</Text> : null}
            <SegmentedControl
              options={TABS.map((option) => ({ ...option, count: counts ? counts[option.value] : null }))}
              value={tab}
              onChange={setTab}
              accessibilityLabel="Tournament status"
            />
          </View>
        }
        ItemSeparatorComponent={Separator}
        renderItem={({ item }) => (
          <Animated.View style={{ width: contentWidth, opacity }}>
            <TournamentCard
              tournament={item}
              onOpen={(tournament) => pushOnce(`/tournament/${tournament.id}`)}
              onResults={(tournament) => pushOnce(`/tournament/${tournament.id}/results`)}
              onRules={setRulesFor}
              onJoin={setJoining}
            />
          </Animated.View>
        )}
        ListEmptyComponent={
          <View style={{ width: contentWidth }}>
            {showError ? (
              <ErrorState title="Couldn't load tournaments" onRetry={() => load(tab)} />
            ) : list === undefined ? (
              <View style={styles.skeletons}>
                {[0, 1, 2].map((index) => (
                  <Skeleton key={index} height={236} radius={16} />
                ))}
              </View>
            ) : (
              <EmptyState icon={tab === 'completed' ? 'trophy' : tab === 'ongoing' ? 'play' : 'calendar'} title={EMPTY[tab].title} message={EMPTY[tab].message} />
            )}
          </View>
        }
        showsVerticalScrollIndicator={false}
      />
      <RulesSheet tournament={rulesFor} onClose={() => setRulesFor(null)} />
      <JoinSheet tournament={joining} onClose={() => setJoining(null)} onJoined={() => void load(tab)} />
    </View>
  );
}

function Separator() {
  return <View style={{ height: SPACE.sm }} />;
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.xs },
  header: { gap: SPACE.md, marginBottom: SPACE.md },
  intro: { ...TYPE.body, color: c.fg2 },
  skeletons: { gap: SPACE.sm },
}));
