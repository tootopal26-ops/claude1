import { useRef, useState } from 'react';
import { Animated, StyleSheet, Text, View } from 'react-native';

import { DURATION, EASE, PRESS_SCALE } from '../../theme/motion';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { ASPECT, RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { Game } from '../../types';
import { PressableScale } from '../interaction/PressableScale';
import { Skeleton } from '../states/States';
import { BottomFade } from '../visual/BottomFade';

type GameCardProps = {
  game: Game;
  width: number;
  onPress: () => void;
};

/**
 * A Home game card: one 16:9 picture (the admin's artwork, never stretched) with the
 * caption just above its bottom edge — the game's title on the left (up to two lines) and,
 * on the right, a green dot with the number of this game's upcoming tournaments (a grey
 * dot and 0 when there are none), both sitting on the same bottom line so a long title
 * wraps upward instead of colliding with the count. A soft full-width fade keeps the
 * caption readable on any artwork. A missing or failing picture falls back to a neutral
 * frame so the grid stays aligned and tappable.
 */
export function GameCard({ game, width, onPress }: GameCardProps) {
  const { colors } = useTheme();
  const styles = useStyles();
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>(game.thumbnail_url ? 'loading' : 'failed');
  const fade = useRef(new Animated.Value(0)).current;
  const height = Math.round(width / ASPECT.game);
  const upcoming = game.upcoming_tournaments_count;

  return (
    <PressableScale
      onPress={onPress}
      accessibilityRole="button"
      accessibilityHint={`Opens ${game.title} tournaments`}
      scaleTo={PRESS_SCALE.card}
      layoutStyle={{ width }}
      style={[styles.frame, { height }]}
    >
      {game.thumbnail_url && status !== 'failed' ? (
        <Animated.Image
          source={{ uri: game.thumbnail_url }}
          style={[StyleSheet.absoluteFill, { opacity: fade }]}
          resizeMode="cover"
          onLoad={() => {
            setStatus('ready');
            Animated.timing(fade, { toValue: 1, duration: DURATION.card, easing: EASE.out, useNativeDriver: true }).start();
          }}
          onError={() => setStatus('failed')}
          accessibilityIgnoresInvertColors
        />
      ) : (
        <View style={styles.placeholder}>
          <Icon name="image" size={28} />
        </View>
      )}
      {status === 'loading' && <Skeleton width="100%" height={height} radius={0} style={StyleSheet.absoluteFill} />}

      <BottomFade color={colors.bg} />
      <View style={styles.caption}>
        <Text style={styles.title} numberOfLines={2}>
          {game.title}
        </Text>
        <View style={styles.count} accessibilityLabel={`${upcoming} upcoming`}>
          <View style={[styles.dot, upcoming === 0 && styles.dotIdle]} />
          <Text style={[styles.countText, upcoming === 0 && styles.countIdle]}>{upcoming}</Text>
        </View>
      </View>
    </PressableScale>
  );
}

export function GameCardSkeleton({ width }: { width: number }) {
  return (
    <View style={{ width }} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <Skeleton width={width} height={Math.round(width / ASPECT.game)} radius={RADIUS.lg} />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  frame: { borderRadius: RADIUS.lg, overflow: 'hidden', backgroundColor: c.surface2, borderWidth: 1, borderColor: c.border },
  placeholder: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center', paddingBottom: SPACE.lg },
  // A small, fixed inset from the bottom and sides: low on the art, clear of the rounded corners.
  caption: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    flexDirection: 'row',
    alignItems: 'flex-end',
    gap: SPACE.xs,
    paddingHorizontal: SPACE.sm - 2,
    paddingBottom: 7,
  },
  title: { ...TYPE.cardTitle, fontSize: 14, lineHeight: 17, color: c.fg, flex: 1, minWidth: 0 },
  count: { flexDirection: 'row', alignItems: 'center', gap: 5, flexShrink: 0, height: 17 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: s.success },
  dotIdle: { backgroundColor: c.fg3 },
  countText: { ...TYPE.bodyStrong, fontSize: 14, color: s.success, fontVariant: ['tabular-nums'] },
  countIdle: { color: c.fg3 },
}));
