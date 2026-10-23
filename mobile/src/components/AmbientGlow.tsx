import { useMemo } from 'react';
import { View } from 'react-native';
import Svg, { Defs, RadialGradient, Rect, Stop } from 'react-native-svg';

import { GLASS } from '../theme/glass';
import { useTheme } from '../theme/ThemeContext';

let instance = 0;

type Props = {
  /** How far down the screen the glow reaches. */
  height?: number;
};

/**
 * A quiet brand-tinted glow at the top of a screen — the one thing the translucent cards have
 * to show. It is decorative: hidden from screen readers, never intercepts touches, and drawn
 * once (no animation, no blur) so it costs nothing while scrolling. Same recipe as the
 * `.mm-ambient` backdrop in the Admin and Staff panels.
 */
export function AmbientGlow({ height = 360 }: Props) {
  const { colors } = useTheme();
  const id = useMemo(() => `mm-glow-${++instance}`, []);

  return (
    <View
      pointerEvents="none"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      style={{ position: 'absolute', top: 0, left: 0, right: 0, height }}
    >
      <Svg width="100%" height="100%">
        <Defs>
          <RadialGradient id={`${id}-a`} cx="78%" cy="0%" rx="75%" ry="95%" fx="78%" fy="0%">
            <Stop offset="0" stopColor={colors.brand} stopOpacity={GLASS.glow} />
            <Stop offset="1" stopColor={colors.brand} stopOpacity={0} />
          </RadialGradient>
          <RadialGradient id={`${id}-b`} cx="0%" cy="35%" rx="60%" ry="60%" fx="0%" fy="35%">
            <Stop offset="0" stopColor={colors.brand} stopOpacity={GLASS.glowSoft} />
            <Stop offset="1" stopColor={colors.brand} stopOpacity={0} />
          </RadialGradient>
        </Defs>
        <Rect x="0" y="0" width="100%" height="100%" fill={`url(#${id}-a)`} />
        <Rect x="0" y="0" width="100%" height="100%" fill={`url(#${id}-b)`} />
      </Svg>
    </View>
  );
}
