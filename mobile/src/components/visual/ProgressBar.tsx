import { useEffect, useRef } from 'react';
import { Animated, View, type StyleProp, type ViewStyle } from 'react-native';

import { DURATION, EASE, useReducedMotion } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS } from '../../theme/tokens';

type Props = {
  /** 0–1; values outside are clamped. */
  value: number;
  tone?: 'brand' | 'success';
  height?: number;
  style?: StyleProp<ViewStyle>;
  accessibilityLabel?: string;
};

/**
 * A thin progress track. It moves only when the value it is given changes (always a value
 * the server confirmed), with a short ease — immediately under reduced motion.
 */
export function ProgressBar({ value, tone = 'brand', height = 8, style, accessibilityLabel }: Props) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const reduced = useReducedMotion();
  const clamped = Math.max(0, Math.min(1, Number.isFinite(value) ? value : 0));
  const progress = useRef(new Animated.Value(clamped)).current;

  useEffect(() => {
    if (reduced) {
      progress.setValue(clamped);
      return;
    }
    Animated.timing(progress, { toValue: clamped, duration: DURATION.content, easing: EASE.out, useNativeDriver: false }).start();
  }, [clamped, reduced, progress]);

  return (
    <View
      style={[styles.track, { height, borderRadius: height / 2 }, style]}
      accessibilityRole="progressbar"
      accessibilityLabel={accessibilityLabel}
      accessibilityValue={{ min: 0, max: 100, now: Math.round(clamped * 100) }}
    >
      <Animated.View
        style={[
          styles.fill,
          { borderRadius: height / 2, backgroundColor: tone === 'success' ? semanticColors.success : colors.brand },
          { width: progress.interpolate({ inputRange: [0, 1], outputRange: ['0%', '100%'] }) },
        ]}
      />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  track: { width: '100%', backgroundColor: c.surface3, overflow: 'hidden', borderRadius: RADIUS.pill },
  fill: { height: '100%' },
}));
