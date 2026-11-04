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

// The button's own fixed palette (not a theme token): this is the one green "go" action in
// the app, so it keeps this exact green in every one of the 7 colour themes rather than
// following the active theme's brand colour.
const GREEN_LIGHT = '#22C55E';
const GREEN_MID = '#16A34A';
const GREEN_DARK = '#15803D';

/**
 * The Spin screen's hero action: a large premium green button reading "SPIN" with a "Watch
 * Ad to Spin" subtitle under a TV glyph. Deliberately its own component rather than the
 * shared <Button> — nothing else in the app needs a two-line label, a green glow this size,
 * or this exact press weight. The gradient/sheen are a decorative <Svg> fill only (added to
 * scripts/check-no-raw-currency.js's allow-list on that basis — no coin or currency imagery).
 *
 * `styles.base` carries a solid GREEN_MID backgroundColor as a hard fallback behind the Svg
 * gradient — react-native-svg's percentage-sized fills need the parent's layout to have
 * already resolved, which can lag a frame on some Android devices; without the fallback that
 * shows as a colourless button for that frame instead of simply the flat green beneath the
 * gradient.
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
          <LinearGradient id="mm-spin-fill" x1="0" y1="0" x2="1" y2="1">
            <Stop offset="0" stopColor={GREEN_LIGHT} stopOpacity={1} />
            <Stop offset="0.55" stopColor={GREEN_MID} stopOpacity={1} />
            <Stop offset="1" stopColor={GREEN_DARK} stopOpacity={1} />
          </LinearGradient>
          {/* A soft light catching the top edge, like a glass highlight — subtle, not a glow. */}
          <RadialGradient id="mm-spin-sheen" cx="50%" cy="-15%" rx="70%" ry="55%" fx="50%" fy="-15%">
            <Stop offset="0" stopColor="#ffffff" stopOpacity={0.28} />
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
              <Icon name="tv" size={16} color="#ffffff" />
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
    // A controlled green shadow — grounded, not a neon bloom. borderRadius is required here,
    // matching `base` exactly: on Android, `elevation` paints THIS view's own rectangular
    // surface as its shadow-casting layer, so without a matching radius its square corners
    // show as solid black patches peeking out from behind the rounded, green-filled child
    // below (`base`) — this is the one view in the whole app using `elevation`, which is
    // exactly why nothing else in the codebase already carries this fix.
    borderRadius: RADIUS.xl,
    shadowColor: GREEN_DARK,
    shadowOpacity: 0.4,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 9,
  },
  base: {
    height: SIZE.button + 18,
    borderRadius: RADIUS.xl,
    overflow: 'hidden',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: GREEN_MID,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.25)',
  },
  pressed: { opacity: 0.88 },
  disabled: { opacity: 0.45 },
  content: { alignItems: 'center', gap: 6 },
  title: { ...TYPE.hero, fontSize: 28, fontWeight: '800', letterSpacing: 2.5, color: '#ffffff' },
  subtitleRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  subtitle: { ...TYPE.bodyStrong, fontSize: 15, color: '#ffffff' },
});
