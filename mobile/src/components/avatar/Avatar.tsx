import { useEffect, useMemo, useRef, useState } from 'react';
import { Animated, Image, Text, View } from 'react-native';
import Svg, { Circle } from 'react-native-svg';

import { DURATION, EASE, useReducedMotion } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';

/**
 * Ring variants by meaning, never by user setting:
 *  home / ranked — green (the player's own avatar; leaderboard ranks 4–100)
 *  first / second / third — gold / silver / bronze on the podium
 *  none — no ring
 */
export type AvatarRing = 'home' | 'first' | 'second' | 'third' | 'ranked' | 'none';

type Props = {
  username: string;
  uri?: string | null;
  size: number;
  ring?: AvatarRing;
  /** A slow, faint pulse of the glow (off when the system asks for reduced motion). */
  pulse?: boolean;
};

const RING_WIDTH = 2;
const GAP = 2;
const GLOW = 5;

/**
 * A circular player picture inside a thin coloured ring with a soft glow around its
 * circumference. The glow is drawn as two faint wider strokes of the same colour — there is
 * no shadow anywhere. Without a picture (players cannot upload one yet) it shows the
 * player's initials, the default avatar used across the app.
 */
export function Avatar({ username, uri, size, ring = 'none', pulse = false }: Props) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const reduced = useReducedMotion();
  const [failed, setFailed] = useState(false);
  const glow = useRef(new Animated.Value(1)).current;

  const color = useMemo(() => {
    switch (ring) {
      case 'first':
        return semanticColors.rankGold;
      case 'second':
        return semanticColors.rankSilver;
      case 'third':
        return semanticColors.rankBronze;
      case 'home':
      case 'ranked':
        return semanticColors.success;
      default:
        return null;
    }
  }, [ring, semanticColors]);

  useEffect(() => {
    if (!pulse || reduced || color === null) {
      glow.setValue(1);
      return;
    }
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(glow, { toValue: 0.55, duration: DURATION.ambient / 2, easing: EASE.inOut, useNativeDriver: true }),
        Animated.timing(glow, { toValue: 1, duration: DURATION.ambient / 2, easing: EASE.inOut, useNativeDriver: true }),
      ]),
    );
    loop.start();

    return () => loop.stop();
  }, [pulse, reduced, color, glow]);

  // The layout box holds the picture and its ring; the glow draws outside it, so it never
  // pushes neighbouring content away.
  const box = color === null ? size : size + (RING_WIDTH + GAP) * 2;
  const outer = color === null ? size : size + (RING_WIDTH + GAP + GLOW) * 2;
  const offset = (box - outer) / 2;
  const center = outer / 2;
  const ringRadius = size / 2 + GAP + RING_WIDTH / 2;
  const initials = username.replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || '?';

  return (
    <View style={{ width: box, height: box, alignItems: 'center', justifyContent: 'center' }} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {color !== null && (
        <>
          <Animated.View style={[styles.layer, { top: offset, left: offset, opacity: glow }]} pointerEvents="none">
            <Svg width={outer} height={outer}>
              <Circle cx={center} cy={center} r={ringRadius + 2} stroke={color} strokeOpacity={0.16} strokeWidth={6} fill="none" />
              <Circle cx={center} cy={center} r={ringRadius + 1} stroke={color} strokeOpacity={0.28} strokeWidth={3} fill="none" />
            </Svg>
          </Animated.View>
          <Svg width={outer} height={outer} style={[styles.layer, { top: offset, left: offset }]} pointerEvents="none">
            <Circle cx={center} cy={center} r={ringRadius} stroke={color} strokeWidth={RING_WIDTH} fill="none" />
          </Svg>
        </>
      )}
      <View style={[styles.face, { width: size, height: size, borderRadius: size / 2 }]}>
        {uri && !failed ? (
          <Image source={{ uri }} style={{ width: size, height: size }} resizeMode="cover" onError={() => setFailed(true)} />
        ) : (
          <Text style={[styles.initials, { fontSize: Math.max(12, Math.round(size * 0.36)), color: colors.brandText }]}>{initials}</Text>
        )}
      </View>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  layer: { position: 'absolute', top: 0, left: 0 },
  face: { overflow: 'hidden', backgroundColor: c.brandSoft, alignItems: 'center', justifyContent: 'center' },
  initials: { fontWeight: '800' },
}));
