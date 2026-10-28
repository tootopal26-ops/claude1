import { useScrollToTop } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { RefreshControl, ScrollView, Text, useWindowDimensions, View, type ScrollViewInstance } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAds } from '../../src/ads/AdsContext';
import { AdSlot } from '../../src/ads/AdSlot';
import { DailyRewardPopup } from '../../src/ads/DailyRewardPopup';
import { fetchHomeBanners } from '../../src/api/banners';
import { fetchGames, fetchTournaments } from '../../src/api/games';
import { useAuth } from '../../src/auth/AuthContext';
import { AmbientGlow } from '../../src/components/AmbientGlow';
import { GameCard, GameCardSkeleton } from '../../src/components/cards/GameCard';
import { HeroCarousel, type HeroBanner } from '../../src/components/carousel/HeroCarousel';
import { HomeHeader } from '../../src/components/home/HomeHeader';
import { ShortcutCards } from '../../src/components/home/ShortcutCards';
import { useBottomNavSpace } from '../../src/components/navigation/BottomNav';
import { EmptyState, ErrorState } from '../../src/components/states/States';
import { useRefreshSectionsOnFocus, useSections } from '../../src/sections/SectionsContext';
import { makeStyles, useTheme } from '../../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, SPACE, TYPE } from '../../src/theme/tokens';
import type { Game, TournamentTab } from '../../src/types';
import { openAppLink } from '../../src/utils/links';
import { pushOnce } from '../../src/utils/navigation';
import { useRefreshWalletOnFocus, useWallet } from '../../src/wallet/WalletContext';

/**
 * Home, top to bottom: the header (avatar, welcome, wallet pill, bell), the Admin-managed
 * 16:9 hero carousel, the Ongoing / Upcoming / Completed shortcuts with the server's counts,
 * and the Esports games grid. Every part loads on its own, so a slow or failed section never
 * hides the others (the balance in particular is always visible).
 */
export default function HomeScreen() {
  const { user } = useAuth();
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const bottomSpace = useBottomNavSpace();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const cardWidth = Math.floor((contentWidth - SPACE.sm) / 2);
  const scrollRef = useRef<ScrollViewInstance>(null);
  useScrollToTop(scrollRef);
  useRefreshWalletOnFocus();
  useRefreshSectionsOnFocus();
  const { refresh: refreshWallet } = useWallet();
  const { refresh: refreshSections } = useSections();
  const { showInterstitial } = useAds();
  const openingGame = useRef(false);

  const [banners, setBanners] = useState<HeroBanner[] | null>(null);
  const [bannersFailed, setBannersFailed] = useState(false);
  const [counts, setCounts] = useState<Record<TournamentTab, number> | null | 'error'>(null);
  const [games, setGames] = useState<Game[] | null>(null);
  const [gamesFailed, setGamesFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const loadBanners = useCallback(async () => {
    try {
      const response = await fetchHomeBanners();
      setBanners(response.data);
      setBannersFailed(false);
    } catch {
      setBanners((current) => current ?? []);
      setBannersFailed(true);
    }
  }, []);

  const loadCounts = useCallback(async () => {
    try {
      const response = await fetchTournaments({ status: 'upcoming' });
      setCounts(response.meta.counts);
    } catch {
      setCounts((current) => (current && current !== 'error' ? current : 'error'));
    }
  }, []);

  const loadGames = useCallback(async () => {
    setGamesFailed(false);
    try {
      const response = await fetchGames();
      setGames(response.data);
    } catch {
      setGamesFailed(true);
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    void loadBanners();
    void loadCounts();
    void loadGames();
  }, [user, loadBanners, loadCounts, loadGames]);

  const onRefresh = async () => {
    setRefreshing(true);
    await Promise.all([loadBanners(), loadCounts(), loadGames(), refreshWallet({ force: true }), refreshSections()]);
    setRefreshing(false);
  };

  // The optional game-card interstitial (only if Admin enabled it and its caps allow). Without
  // an ad ready the game opens straight away; with one, it opens as soon as the ad closes.
  const openGame = async (game: Game) => {
    if (openingGame.current) return;
    openingGame.current = true;
    try {
      await showInterstitial('home_game_card');
    } finally {
      openingGame.current = false;
      pushOnce(`/game/${game.id}`);
    }
  };

  if (!user) return null;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView
        ref={scrollRef}
        contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xs, paddingBottom: bottomSpace }]}
        refreshControl={
          <RefreshControl tintColor={colors.brandText} colors={[colors.brand]} progressBackgroundColor={colors.surface3} refreshing={refreshing} onRefresh={onRefresh} progressViewOffset={insets.top} />
        }
        showsVerticalScrollIndicator={false}
      >
        <View style={[styles.column, { width: contentWidth }]}>
          <HomeHeader username={user.username} />

          <AdSlot placement="home_hero" />

          <HeroCarousel
            banners={banners}
            width={contentWidth}
            label="Featured"
            emptyText={bannersFailed ? "Couldn't load banners" : 'Mini Max Esports'}
            onOpenLink={(banner) => openAppLink(banner.link_url)}
          />

          <ShortcutCards counts={counts} width={contentWidth} />

          <AdSlot placement="home_between" />

          <View style={styles.sectionHead}>
            <Text style={styles.sectionTitle} accessibilityRole="header">
              Esports Games
            </Text>
            {games && games.length > 0 ? <Text style={styles.sectionMeta}>{games.length === 1 ? '1 game' : `${games.length} games`}</Text> : null}
          </View>

          {gamesFailed && games === null ? (
            <ErrorState compact title="Couldn't load the games" onRetry={loadGames} />
          ) : games === null ? (
            <View style={styles.grid}>
              {[0, 1, 2, 3].map((index) => (
                <GameCardSkeleton key={index} width={cardWidth} />
              ))}
            </View>
          ) : games.length === 0 ? (
            <EmptyState compact icon="tournaments" title="No games yet" message="Games appear here as soon as they are added." />
          ) : (
            <View style={styles.grid}>
              {games.map((game) => (
                <GameCard key={game.id} game={game} width={cardWidth} onPress={() => void openGame(game)} />
              ))}
            </View>
          )}
        </View>
      </ScrollView>
      <DailyRewardPopup />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center' },
  column: { gap: SPACE.lg },
  sectionHead: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: -SPACE.xs },
  sectionTitle: { ...TYPE.section, color: c.fg },
  sectionMeta: { ...TYPE.meta, color: c.fg3 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', columnGap: SPACE.sm, rowGap: SPACE.sm },
}));
