import { useEffect, useRef } from 'react';
import { Animated, Text, View } from 'react-native';

import { Artwork, ARTWORK_SIZE } from '../../theme/artwork';
import { withOpacity } from '../../theme/glass';
import { DURATION, EASE, useReducedMotion } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../../theme/tokens';
import type { LeaderboardEntry } from '../../types';
import { formatCoins } from '../../utils/coins';
import { Avatar, type AvatarRing } from '../avatar/Avatar';
import { CoinAmount } from '../CoinAmount';
import { CoinAsset } from '../CoinAsset';

type Props = {
  entries: LeaderboardEntry[];
  myUserId: number;
  /** Changes whenever the period changes, to replay the short entrance. */
  animationKey: string;
};

const PLACES: { rank: 1 | 2 | 3; ring: AvatarRing }[] = [
  { rank: 2, ring: 'second' },
  { rank: 1, ring: 'first' },
  { rank: 3, ring: 'third' },
];

/**
 * The top three: second on the left, first raised in the middle, third on the right, in
 * gold, silver and bronze rings (no shadows), each marked with the supplied 1 / 2 / 3 medal
 * (gold, silver, bronze — the first-place medal a little larger). Each shows the player's
 * earnings for the period from the server; an empty place stays empty rather than showing
 * a stand-in.
 */
export function Podium({ entries, myUserId, animationKey }: Props) {
  const styles = useStyles();
  const reduced = useReducedMotion();
  const entrance = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    entrance.setValue(0);
    Animated.timing(entrance, { toValue: 1, duration: DURATION.content, easing: EASE.out, useNativeDriver: true }).start();
  }, [animationKey, entrance]);

  return (
    <View style={styles.row} accessibilityLabel="Top three">
      {PLACES.map((place, index) => {
        const entry = entries.find((candidate) => candidate.rank === place.rank) ?? null;
        const delay = index === 1 ? 0 : 0.15;
        const opacity = entrance.interpolate({ inputRange: [delay, 1], outputRange: [0, 1], extrapolate: 'clamp' });
        const translateY = entrance.interpolate({ inputRange: [delay, 1], outputRange: [reduced ? 0 : 8, 0], extrapolate: 'clamp' });

        return (
          <Animated.View key={place.rank} style={[styles.place, place.rank === 1 && styles.first, { opacity, transform: [{ translateY }] }]}>
            <Place rank={place.rank} ring={place.ring} entry={entry} isMe={entry?.user_id === myUserId} />
          </Animated.View>
        );
      })}
    </View>
  );
}

function Place({ rank, ring, entry, isMe }: { rank: 1 | 2 | 3; ring: AvatarRing; entry: LeaderboardEntry | null; isMe: boolean }) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const metal = rank === 1 ? semanticColors.rankGold : rank === 2 ? semanticColors.rankSilver : semanticColors.rankBronze;
  const size = rank === 1 ? SIZE.avatarPodiumFirst : SIZE.avatarPodium;

  if (!entry) {
    return (
      <View style={styles.placeInner} accessibilityLabel={`Rank ${rank}, no player yet`}>
        <View style={[styles.empty, { width: size, height: size, borderRadius: size / 2 }]}>
          <Text style={styles.emptyText}>—</Text>
        </View>
        <Medal rank={rank} />
        <Text style={styles.nameMuted}>No player yet</Text>
      </View>
    );
  }

  return (
    <View
      style={[styles.placeInner, { backgroundColor: withOpacity(metal, 0.07), borderColor: withOpacity(metal, 0.28) }]}
      accessibilityLabel={`Rank ${rank}, ${entry.username}${isMe ? ' (you)' : ''}, earned ${entry.score} coins`}
    >
      <Avatar username={entry.username} size={size} ring={ring} />
      <Medal rank={rank} />
      <Text style={styles.name} numberOfLines={1}>
        {entry.username}
        {isMe ? <Text style={{ color: colors.brandText }}> · you</Text> : null}
      </Text>
      <CoinAmount value={entry.score} size={14} textStyle={styles.score} style={styles.scoreRow} />
      {entry.prize !== null ? (
        <View style={styles.prize}>
          <Text style={styles.prizeText}>Reward</Text>
          <CoinAsset size={11} />
          <Text style={styles.prizeText}>{formatCoins(entry.prize)}</Text>
        </View>
      ) : null}
    </View>
  );
}

/** The supplied rank medal, overlapping the bottom of the avatar ring. */
function Medal({ rank }: { rank: 1 | 2 | 3 }) {
  const styles = useStyles();
  const size = rank === 1 ? ARTWORK_SIZE.medalFirst : ARTWORK_SIZE.medal;

  return <Artwork name={rank === 1 ? 'medal1' : rank === 2 ? 'medal2' : 'medal3'} size={size} style={styles.medal} />;
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: { flexDirection: 'row', alignItems: 'flex-end', gap: SPACE.xs },
  place: { flex: 1, minWidth: 0 },
  first: { marginBottom: SPACE.md },
  placeInner: {
    alignItems: 'center',
    paddingTop: SPACE.sm,
    paddingBottom: SPACE.sm,
    paddingHorizontal: SPACE.xxs,
    borderRadius: RADIUS.lg,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  medal: { marginTop: -16 },
  name: { ...TYPE.bodyStrong, fontSize: 13, color: c.fg, marginTop: SPACE.xxs, maxWidth: '100%' },
  nameMuted: { ...TYPE.meta, color: c.fg3, marginTop: SPACE.xxs },
  scoreRow: { gap: 4, marginTop: 2 },
  score: { ...TYPE.bodyStrong, fontSize: 14, color: c.fg },
  prize: { flexDirection: 'row', alignItems: 'center', gap: 3, marginTop: 2 },
  prizeText: { ...TYPE.label, color: c.fg3 },
  empty: { backgroundColor: c.surface2, borderWidth: 1, borderColor: c.border, borderStyle: 'dashed', alignItems: 'center', justifyContent: 'center' },
  emptyText: { ...TYPE.section, color: c.fg3 },
}));
