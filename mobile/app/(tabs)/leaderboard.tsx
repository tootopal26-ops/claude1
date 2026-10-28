import { useIsFocused, useScrollToTop } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Animated, AppState, FlatList, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '../../src/api/client';
import { fetchLeaderboard, fetchLeaderboardBanners } from '../../src/api/leaderboards';
import { useAuth } from '../../src/auth/AuthContext';
import { AmbientGlow } from '../../src/components/AmbientGlow';
import { HeroCarousel, type HeroBanner } from '../../src/components/carousel/HeroCarousel';
import { SegmentedControl } from '../../src/components/controls/SegmentedControl';
import { Podium } from '../../src/components/leaderboard/Podium';
import { useAds } from '../../src/ads/AdsContext';
import { AdSlot, feedAdPositions } from '../../src/ads/AdSlot';
import { RankRow } from '../../src/components/leaderboard/RankRow';
import { useBottomNavSpace } from '../../src/components/navigation/BottomNav';
import { SectionCard } from '../../src/components/SectionCard';
import { EmptyState, ErrorState, Skeleton } from '../../src/components/states/States';
import { StatusChip } from '../../src/components/StatusChip';
import { UnlockSectionDialog } from '../../src/components/UnlockSectionDialog';
import { useRefreshSectionsOnFocus, useSections } from '../../src/sections/SectionsContext';
import { DURATION, EASE } from '../../src/theme/motion';
import { makeStyles, useTheme } from '../../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../../src/theme/tokens';
import type { Leaderboard } from '../../src/types';
import { openAppLink } from '../../src/utils/links';
import { formatDateTime } from '../../src/utils/time';

type Period = Leaderboard['type'];

const PERIODS: { value: Period; label: string }[] = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
];

/**
 * The Leaderboard tab, top to bottom: title, the Admin-managed 16:9 banner carousel, the
 * Daily / Weekly / Monthly tabs, the podium (2 · 1 · 3) and ranks 4–100. Every figure is the
 * server's (earnings and each rank's reward for the period); nothing is filled in locally.
 * When the section is locked for this player the banners stay and the unlock card replaces
 * the rankings — whether it is locked is decided by the server, never here.
 */
export default function LeaderboardScreen() {
  const { user } = useAuth();
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const bottomSpace = useBottomNavSpace();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const listRef = useRef<FlatList>(null);
  const { placement } = useAds();
  useScrollToTop(listRef);
  useRefreshSectionsOnFocus();

  const { sections, refresh: refreshSections } = useSections();
  const [lockedByServer, setLockedByServer] = useState(false);
  const [unlockOpen, setUnlockOpen] = useState(false);
  const section = sections?.find((item) => item.key === 'leaderboard') ?? null;
  const locked = section ? !section.unlocked : lockedByServer;

  const [period, setPeriod] = useState<Period>('daily');
  const cache = useRef<Partial<Record<Period, Leaderboard>>>({});
  const [board, setBoard] = useState<Leaderboard | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [banners, setBanners] = useState<HeroBanner[] | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const contentOpacity = useRef(new Animated.Value(1)).current;
  const requestId = useRef(0);

  const loadBanners = useCallback(async () => {
    try {
      const response = await fetchLeaderboardBanners();
      setBanners(response.data);
    } catch {
      setBanners((current) => current ?? []);
    }
  }, []);

  const load = useCallback(
    async (next: Period) => {
      const id = ++requestId.current;
      try {
        const response = await fetchLeaderboard(next);
        cache.current[next] = response.data;
        if (id !== requestId.current) return;
        setBoard(response.data);
        setState('ready');
        setLockedByServer(false);
      } catch (error) {
        if (id !== requestId.current) return;
        if (error instanceof ApiError && error.code === 'section_locked') {
          setLockedByServer(true);
          void refreshSections();
          return;
        }
        setState((current) => (cache.current[next] ? current : 'error'));
      } finally {
        if (id === requestId.current) {
          Animated.timing(contentOpacity, { toValue: 1, duration: DURATION.card, easing: EASE.out, useNativeDriver: true }).start();
        }
      }
    },
    [refreshSections, contentOpacity],
  );

  useEffect(() => {
    if (!user) return;
    void loadBanners();
  }, [user, loadBanners]);

  // Switching periods: show what we already have for it at once, dimmed, and refresh it.
  useEffect(() => {
    if (!user || locked) return;
    const cached = cache.current[period];
    if (cached) {
      setBoard(cached);
      setState('ready');
    } else {
      setState((current) => (current === 'ready' ? 'ready' : 'loading'));
    }
    Animated.timing(contentOpacity, { toValue: cached ? 0.6 : 0.35, duration: DURATION.instant, easing: EASE.in, useNativeDriver: true }).start();
    void load(period);
  }, [user, period, locked, load, contentOpacity]);

  // Provisional standings are recomputed by the server on a schedule, so re-read them once a
  // minute while this tab is on screen and the app is in the foreground — never in the
  // background, never for a finalized period.
  const focused = useIsFocused();
  const provisional = board?.status === 'provisional';
  useEffect(() => {
    if (!focused || locked || !provisional) return;
    const timer = setInterval(() => {
      if (AppState.currentState === 'active') void load(period);
    }, 60000);
    return () => clearInterval(timer);
  }, [focused, locked, provisional, period, load]);

  const onRefresh = async () => {
    setRefreshing(true);
    await Promise.all([loadBanners(), refreshSections(), locked ? Promise.resolve() : load(period)]);
    setRefreshing(false);
  };

  if (!user) return null;

  const hero = (
    <View style={styles.headerBlock}>
      <Text style={styles.screenTitle} accessibilityRole="header">
        Leaderboard
      </Text>
      <HeroCarousel banners={banners} width={contentWidth} label="Leaderboard banners" emptyText="Top players, every day" onOpenLink={(banner) => openAppLink(banner.link_url)} />
    </View>
  );

  const refreshControl = (
    <RefreshControl tintColor={colors.brandText} colors={[colors.brand]} progressBackgroundColor={colors.surface3} refreshing={refreshing} onRefresh={onRefresh} progressViewOffset={insets.top} />
  );

  if (locked) {
    return (
      <View style={styles.screen}>
        <AmbientGlow />
        <FlatList
          ref={listRef}
          data={[]}
          renderItem={() => null}
          contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xs, paddingBottom: bottomSpace }]}
          refreshControl={refreshControl}
          ListHeaderComponent={
            <View style={[styles.column, { width: contentWidth }]}>
              {hero}
              {section ? (
                <SectionCard section={section} onUnlock={() => setUnlockOpen(true)} />
              ) : (
                <ErrorState compact title="The leaderboard is locked" message="We couldn't load its unlock price." onRetry={() => refreshSections()} />
              )}
              <Text style={styles.lockedNote}>Unlock the leaderboard once with coins and the daily, weekly and monthly rankings stay open for you.</Text>
            </View>
          }
        />
        <UnlockSectionDialog section={section} visible={unlockOpen} onClose={() => setUnlockOpen(false)} />
      </View>
    );
  }

  const ranked = board?.entries.filter((entry) => entry.rank > 3) ?? [];
  const myEntryListed = board?.entries.some((entry) => entry.user_id === user.id) ?? false;
  const adAfter = feedAdPositions(ranked.length, placement('leaderboard_feed')?.insert_every);

  const header = (
    <View style={[styles.column, { width: contentWidth }]}>
      {hero}
      <SegmentedControl options={PERIODS} value={period} onChange={setPeriod} accessibilityLabel="Leaderboard period" />
      {state === 'loading' ? (
        <View style={styles.podiumSkeleton}>
          <Skeleton width="30%" height={150} radius={RADIUS.lg} />
          <Skeleton width="34%" height={176} radius={RADIUS.lg} />
          <Skeleton width="30%" height={150} radius={RADIUS.lg} />
        </View>
      ) : state === 'error' ? (
        <ErrorState title="Couldn't load the leaderboard" onRetry={() => load(period)} />
      ) : board ? (
        <Animated.View style={[styles.boardHead, { opacity: contentOpacity }]}>
          <View style={styles.statusRow}>
            <StatusChip tone={board.status === 'provisional' ? 'warning' : 'success'} label={board.status === 'provisional' ? 'Provisional standings' : 'Final standings'} />
            <Text style={styles.period} numberOfLines={1}>
              {board.status === 'provisional' ? `Ends ${formatDateTime(board.period_end)}` : `Ended ${formatDateTime(board.period_end)}`}
            </Text>
          </View>
          {board.entries.length === 0 ? (
            <EmptyState icon="trophy" title="No rankings yet" message="Rankings appear once tournaments in this period have published results." />
          ) : (
            <Podium entries={board.entries} myUserId={user.id} animationKey={`${board.type}-${board.period_start}`} />
          )}
        </Animated.View>
      ) : null}
    </View>
  );

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <FlatList
        ref={listRef}
        extraData={adAfter}
        data={state === 'ready' ? ranked : []}
        keyExtractor={(item) => `${item.rank}-${item.user_id}`}
        contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xs, paddingBottom: bottomSpace }]}
        refreshControl={refreshControl}
        ListHeaderComponent={header}
        ListHeaderComponentStyle={styles.listHeader}
        ItemSeparatorComponent={Separator}
        renderItem={({ item, index }) => (
          <Animated.View style={{ width: contentWidth, opacity: contentOpacity }}>
            <RankRow entry={item} isMe={item.user_id === user.id} />
            {/* A labelled feed ad after every N players (Admin: 5, 8 or off); ranks never shift. */}
            {adAfter.has(index) ? <AdSlot placement="leaderboard_feed" style={styles.feedAd} /> : null}
          </Animated.View>
        )}
        ListFooterComponent={
          state === 'ready' && board?.my_entry && !myEntryListed ? (
            <View style={[styles.footer, { width: contentWidth }]}>
              <Text style={styles.footerLabel}>Your position</Text>
              <RankRow entry={board.my_entry} isMe />
            </View>
          ) : undefined
        }
        initialNumToRender={12}
        windowSize={7}
        showsVerticalScrollIndicator={false}
      />
    </View>
  );
}

function Separator() {
  return <View style={{ height: SPACE.xs }} />;
}

const useStyles = makeStyles(({ colors: c }) => ({
  feedAd: { marginTop: SPACE.xs },
  screen: { flex: 1 },
  content: { alignItems: 'center' },
  column: { gap: SPACE.lg },
  listHeader: { marginBottom: SPACE.md },
  headerBlock: { gap: SPACE.md },
  screenTitle: { ...TYPE.screenTitle, color: c.fg, minHeight: 44, textAlignVertical: 'center', paddingTop: SPACE.xs },
  boardHead: { gap: SPACE.lg },
  statusRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs, flexWrap: 'wrap', marginTop: -SPACE.xs },
  period: { ...TYPE.meta, color: c.fg3, flexShrink: 1 },
  podiumSkeleton: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between' },
  lockedNote: { ...TYPE.body, color: c.fg3, textAlign: 'center', paddingHorizontal: SPACE.xs },
  footer: { marginTop: SPACE.md, gap: SPACE.xs },
  footerLabel: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.6 },
}));
