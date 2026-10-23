import { useRef, useState } from 'react';
import { Animated, StyleSheet, Text, View } from 'react-native';

import { DURATION, EASE, PRESS_SCALE } from '../../theme/motion';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { Game } from '../../types';
import { PressableScale } from '../interaction/PressableScale';
import { Skeleton } from '../states/States';

type GameCardProps = {
  game: Game;
  width: number;
  onPress: () => void;
};

/**
 * A Home game card: the admin's 16:9 picture (never stretched — the server stores it at
 * exactly 16:9 and the frame keeps that ratio at any width), and below it the title with a
 * green dot and the number of this game's upcoming tournaments. A missing or failing image
 * falls back to a neutral frame so the grid stays aligned and the card stays tappable.
 */
export function GameCard({ game, width, onPress }: GameCardProps) {
  const { colors } = useTheme();
  const styles = useStyles();
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>(game.thumbnail_url ? 'loading' : 'failed');
  const fade = useRef(new Animated.Value(0)).current;
  const upcoming = game.upcoming_tournaments_count;
  const countLabel = upcoming === 1 ? '1 upcoming' : `${upcoming} upcoming`;

  return (
    <PressableScale
      onPress={onPress}
      accessibilityRole="button"
      accessibilityHint={`Opens ${game.title} tournaments`}
      scaleTo={PRESS_SCALE.card}
      layoutStyle={{ width }}
    >
      <View style={[styles.frame, { height: Math.round((width * 9) / 16) }]}>
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
            <Icon name="image" size={24} color={colors.fg3} />
          </View>
        )}
        {status === 'loading' && <Skeleton width="100%" height={Math.round((width * 9) / 16)} radius={0} style={StyleSheet.absoluteFill} />}
      </View>
      <Text style={styles.title} numberOfLines={1}>
        {game.title}
      </Text>
      <View style={styles.meta}>
        <View style={[styles.dot, upcoming === 0 && styles.dotIdle]} />
        <Text style={styles.count} numberOfLines={1}>
          {countLabel}
        </Text>
      </View>
    </PressableScale>
  );
}

export function GameCardSkeleton({ width }: { width: number }) {
  const styles = useStyles();

  return (
    <View style={{ width }} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <Skeleton width={width} height={Math.round((width * 9) / 16)} radius={RADIUS.lg} />
      <Skeleton width="70%" height={14} style={styles.skeletonLine} />
      <Skeleton width="45%" height={12} style={styles.skeletonLineSmall} />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  frame: { borderRadius: RADIUS.lg, overflow: 'hidden', backgroundColor: c.surface2, borderWidth: 1, borderColor: c.border },
  placeholder: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center' },
  title: { ...TYPE.cardTitle, fontSize: 14, color: c.fg, marginTop: SPACE.xs },
  meta: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 2 },
  dot: { width: 7, height: 7, borderRadius: 4, backgroundColor: s.success },
  dotIdle: { backgroundColor: c.fg3 },
  count: { ...TYPE.meta, color: c.fg2 },
  skeletonLine: { marginTop: SPACE.xs },
  skeletonLineSmall: { marginTop: 6 },
}));
