import { useState } from 'react';
import { ActivityIndicator, Image, Pressable, StyleSheet, Text, View } from 'react-native';

import { Icon } from '../theme/icons';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import type { Game } from '../types';

type GameCardProps = {
  game: Game;
  width: number;
  onPress: () => void;
};

/**
 * A home-screen game card: the admin's 16:9 picture (never stretched — the
 * server always stores it at exactly 16:9, and the frame keeps that ratio at
 * any width) with the title on a translucent bar. A missing or failing image
 * falls back to a neutral placeholder so the card stays tappable and aligned.
 */
export function GameCard({ game, width, onPress }: GameCardProps) {
  const { colors } = useTheme();
  const styles = useStyles();
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>(game.thumbnail_url ? 'loading' : 'failed');

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${game.title}. Open tournaments`}
      style={({ pressed }) => [styles.card, { width }, pressed && styles.pressed]}
    >
      <View style={styles.frame}>
        {game.thumbnail_url && status !== 'failed' ? (
          <Image
            source={{ uri: game.thumbnail_url }}
            style={StyleSheet.absoluteFill}
            resizeMode="cover"
            onLoad={() => setStatus('ready')}
            onError={() => setStatus('failed')}
            accessibilityIgnoresInvertColors
          />
        ) : (
          <View style={styles.placeholder}>
            <Icon name="image" size={26} color={colors.fg3} />
          </View>
        )}
        {status === 'loading' && (
          <View style={styles.loading}>
            <ActivityIndicator color={colors.brandText} />
          </View>
        )}
        <View style={styles.titleBar}>
          <Text style={styles.title} numberOfLines={1}>
            {game.title}
          </Text>
        </View>
      </View>
    </Pressable>
  );
}

export function GameCardSkeleton({ width }: { width: number }) {
  const styles = useStyles();

  return (
    <View style={[styles.card, { width }]} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <View style={[styles.frame, styles.skeleton]} />
    </View>
  );
}

const FILL = { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 } as const;

const useStyles = makeStyles(({ colors: c }) => ({
  card: { borderRadius: 14 },
  pressed: { opacity: 0.85 },
  frame: {
    aspectRatio: 16 / 9,
    borderRadius: 14,
    overflow: 'hidden',
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.border,
  },
  skeleton: { backgroundColor: c.surface },
  placeholder: { ...FILL, alignItems: 'center', justifyContent: 'center', backgroundColor: c.surface2 },
  loading: { ...FILL, alignItems: 'center', justifyContent: 'center' },
  // Translucent, not blurred: stable on every Android version, and the white title
  // keeps >4.5:1 against the bar even over a bright picture.
  titleBar: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: 10,
    paddingVertical: 7,
    backgroundColor: `${c.bg}d1`,
    borderTopWidth: 1,
    borderTopColor: c.border,
  },
  title: { color: c.fg, fontSize: 13, fontWeight: '700' },
}));
