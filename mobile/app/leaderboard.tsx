import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { Redirect } from 'expo-router';

import { ApiError } from '../src/api/client';
import { fetchLeaderboard } from '../src/api/leaderboards';
import { useAuth } from '../src/auth/AuthContext';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { BannerCarousel } from '../src/components/BannerCarousel';
import { SectionCard } from '../src/components/SectionCard';
import { StatusChip } from '../src/components/StatusChip';
import { UnlockSectionDialog } from '../src/components/UnlockSectionDialog';
import { useRefreshSectionsOnFocus, useSections } from '../src/sections/SectionsContext';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import type { Leaderboard } from '../src/types';

const TYPES: Leaderboard['type'][] = ['daily', 'weekly', 'monthly'];
const TYPE_LABELS: Record<Leaderboard['type'], string> = {
  daily: 'Daily',
  weekly: 'Weekly',
  monthly: 'Monthly',
};

export default function LeaderboardScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const [type, setType] = useState<Leaderboard['type']>('daily');
  const [leaderboard, setLeaderboard] = useState<Leaderboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [bannerRefresh, setBannerRefresh] = useState(0);
  const { sections, refresh: refreshSections } = useSections();
  const [lockedByServer, setLockedByServer] = useState(false);
  const [unlockOpen, setUnlockOpen] = useState(false);
  useRefreshSectionsOnFocus();

  // The server decides: the section list says whether it is locked, and a refused request does too.
  const section = sections?.find((item) => item.key === 'leaderboard') ?? null;
  const locked = section ? !section.unlocked : lockedByServer;

  const load = useCallback(async (nextType: Leaderboard['type']) => {
    setError(null);
    try {
      const response = await fetchLeaderboard(nextType);
      setLeaderboard(response.data);
    } catch (caught) {
      if (caught instanceof ApiError && caught.code === 'section_locked') {
        setLockedByServer(true);
        void refreshSections();
        return;
      }
      setError('Could not load the leaderboard. Pull to retry.');
    }
  }, [refreshSections]);

  useEffect(() => {
    if (!user || locked) return;
    (async () => {
      setLoading(true);
      await load(type);
      setLoading(false);
    })();
  }, [user, type, load, locked]);

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

  if (locked) {
    return (
      <View style={styles.container}>
        <AmbientGlow />
        <View style={styles.lockedWrap}>
          <View style={styles.carouselWrap}>
            <BannerCarousel refreshKey={bannerRefresh} />
          </View>
          <View style={styles.lockedGap} />
          {section ? (
            <SectionCard section={section} onUnlock={() => setUnlockOpen(true)} />
          ) : (
            <Pressable style={styles.emptyBox} onPress={() => refreshSections()} accessibilityRole="button">
              <Text style={styles.empty}>This section is locked. Tap to load its price.</Text>
            </Pressable>
          )}
          <Text style={styles.lockedNote} accessibilityRole="text">
            The leaderboard is a locked section. Unlock it once with coins and the rankings are yours to keep.
          </Text>
        </View>
        <UnlockSectionDialog section={section} visible={unlockOpen} onClose={() => setUnlockOpen(false)} />
      </View>
    );
  }

  const myEntryInTop = leaderboard?.entries.some((entry) => entry.username === user.username) ?? false;

  const header = (
    <View>
      <View style={styles.carouselWrap}>
        <BannerCarousel refreshKey={bannerRefresh} />
      </View>
      <View style={styles.tabRow}>
        {TYPES.map((t) => (
          <Pressable
            key={t}
            style={[styles.tab, type === t && styles.tabActive]}
            onPress={() => setType(t)}
            accessibilityRole="tab"
            accessibilityState={{ selected: type === t }}
          >
            <Text style={[styles.tabText, type === t && styles.tabTextActive]}>{TYPE_LABELS[t]}</Text>
          </Pressable>
        ))}
      </View>
      {leaderboard && (
        <View style={styles.statusRow}>
          <StatusChip
            tone={leaderboard.status === 'provisional' ? 'warning' : 'success'}
            label={leaderboard.status === 'provisional' ? 'Live standings' : 'Final standings'}
          />
        </View>
      )}
    </View>
  );

  return (
    <View style={styles.container}>
      <AmbientGlow />
      <FlatList
        data={leaderboard?.entries ?? []}
        keyExtractor={(item) => String(item.rank)}
        contentContainerStyle={styles.listContent}
        refreshControl={
          <RefreshControl
            tintColor={colors.brandText}
            colors={[colors.brand]}
            progressBackgroundColor={colors.surface3}
            refreshing={refreshing}
            onRefresh={async () => {
              setRefreshing(true);
              setBannerRefresh((value) => value + 1);
              await load(type);
              setRefreshing(false);
            }}
          />
        }
        ListHeaderComponent={header}
        ListFooterComponent={
          leaderboard?.my_entry && !myEntryInTop ? (
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
        ListEmptyComponent={
          loading ? (
            <ActivityIndicator style={styles.emptyBox} color={colors.brandText} />
          ) : !leaderboard ? (
            <Pressable style={styles.emptyBox} onPress={() => load(type)} accessibilityRole="button">
              <Text style={styles.empty}>{error ?? 'Could not load the leaderboard.'} Tap to retry.</Text>
            </Pressable>
          ) : (
            <Text style={styles.empty}>No results yet for this period.</Text>
          )
        }
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
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  carouselWrap: { alignItems: 'center', paddingTop: 16 },
  lockedWrap: { paddingHorizontal: 16, paddingBottom: 16 },
  lockedGap: { height: 16 },
  lockedNote: { color: c.fg3, fontSize: 13, lineHeight: 19, textAlign: 'center', paddingHorizontal: 8 },
  emptyBox: { marginTop: 32, alignItems: 'center' },
  tabRow: { flexDirection: 'row', paddingVertical: 16, gap: 8 },
  tab: {
    flex: 1,
    minHeight: 44,
    justifyContent: 'center',
    borderRadius: 12,
    alignItems: 'center',
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.border,
  },
  tabActive: { backgroundColor: c.brand, borderColor: c.brand },
  tabText: { color: c.fg2, fontWeight: '600' },
  tabTextActive: { color: c.onBrand },
  listContent: { paddingHorizontal: 16, paddingBottom: 16 },
  statusRow: { marginBottom: 12 },
  empty: { color: c.fg2, textAlign: 'center', marginTop: 32 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: c.border,
  },
  myRow: { backgroundColor: c.brandSoft, borderRadius: 12, paddingHorizontal: 8, borderBottomColor: 'transparent' },
  rank: { color: c.fg2, fontSize: 14, fontWeight: '700', width: 40 },
  playerInfo: { flex: 1 },
  username: { color: c.fg, fontSize: 14, fontWeight: '700' },
  meta: { color: c.fg3, fontSize: 11, marginTop: 2 },
  score: { color: c.brandText, fontSize: 15, fontWeight: '700' },
}));
