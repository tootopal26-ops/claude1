import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import Svg, { Defs, LinearGradient, RadialGradient, Rect, Stop } from 'react-native-svg';

import { PressableScale } from '../components/interaction/PressableScale';
import { Icon } from '../theme/icons';
import { PRESS_SCALE } from '../theme/motion';
import { RADIUS, SIZE, SPACE, TYPE } from '../theme/tokens';

type Props = {
  onPress: () => void;
  loading?: boolean;
  disabled?: boolean;
  label?: string;
};

/**
 * The Spin screen's hero action: a large premium green button reading "SPIN" with a "Watch
 * Ad to Spin" subtitle under a TV glyph. Deliberately its own component rather than the
 * shared <Button> — nothing else in the app needs a two-line label, a green glow this size,
 * or this exact press weight. The gradient/glow are a decorative <Svg> fill only (added to
 * scripts/check-no-raw-currency.js's allow-list on that basis — no coin or currency imagery).
 */
export function SpinButton({ onPress, loading = false, disabled = false, label = 'SPIN' }: Props) {
  const isDisabled = disabled || loading;

  return (
    <PressableScale
      accessibilityRole="button"
      accessibilityLabel={`${label} — watch a rewarded ad to spin the wheel`}
      aria-disabled={isDisabled}
      aria-busy={loading}
      disabled={isDisabled}
      onPress={onPress}
      sound="click"
      haptic="medium"
      scaleTo={PRESS_SCALE.button}
      layoutStyle={styles.layout}
      style={[styles.base, isDisabled && styles.disabled]}
      pressedStyle={styles.pressed}
    >
      <Svg style={StyleSheet.absoluteFill} width="100%" height="100%">
        <Defs>
          <LinearGradient id="mm-spin-fill" x1="0" y1="0" x2="0" y2="1">
            <Stop offset="0" stopColor="#2fd971" stopOpacity={1} />
            <Stop offset="1" stopColor="#0f7a38" stopOpacity={1} />
          </LinearGradient>
          <RadialGradient id="mm-spin-sheen" cx="50%" cy="-10%" rx="75%" ry="65%" fx="50%" fy="-10%">
            <Stop offset="0" stopColor="#ffffff" stopOpacity={0.3} />
            <Stop offset="1" stopColor="#ffffff" stopOpacity={0} />
          </RadialGradient>
        </Defs>
        <Rect x="0" y="0" width="100%" height="100%" fill="url(#mm-spin-fill)" />
        <Rect x="0" y="0" width="100%" height="100%" fill="url(#mm-spin-sheen)" />
      </Svg>
      <View style={styles.content}>
        {loading ? (
          <ActivityIndicator color="#ffffff" />
        ) : (
          <>
            <Text style={styles.title}>{label}</Text>
            <View style={styles.subtitleRow}>
              <Icon name="tv" size={14} color="#ffffff" />
              <Text style={styles.subtitle}>Watch Ad to Spin</Text>
            </View>
          </>
        )}
      </View>
    </PressableScale>
  );
}

const styles = StyleSheet.create({
  layout: {
    shadowColor: '#22c55e',
    shadowOpacity: 0.5,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 8 },
    elevation: 12,
  },
  base: {
    height: SIZE.button + 16,
    borderRadius: RADIUS.xl,
    overflow: 'hidden',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.22)',
  },
  pressed: { opacity: 0.94 },
  disabled: { opacity: 0.5 },
  content: { alignItems: 'center', gap: 4 },
  title: { ...TYPE.hero, fontSize: 26, letterSpacing: 2, color: '#ffffff' },
  subtitleRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xxs },
  subtitle: { ...TYPE.bodyStrong, color: '#ffffff', opacity: 0.92 },
});
