import { useId } from 'react';
import { StyleSheet, View } from 'react-native';
import Svg, { Defs, LinearGradient, Rect, Stop } from 'react-native-svg';

type Props = {
  /** The colour the picture fades into (usually the theme's canvas). */
  color: string;
  /** Share of the frame's height the fade covers, from the bottom. */
  coverage?: number;
  /** Opacity at the very bottom edge. */
  strength?: number;
};

/**
 * A soft dark fade across the full width of the lower part of a picture, so a caption laid
 * over it stays readable on bright and dark artwork alike. It is a gradient, never a hard
 * block, and draws nothing that react-native-svg cannot render on Android.
 */
export function BottomFade({ color, coverage = 0.58, strength = 0.92 }: Props) {
  // Each fade needs its own gradient id: on the web all SVG ids share one document.
  const id = `mm-fade-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

  return (
    <View pointerEvents="none" style={[StyleSheet.absoluteFill, { top: `${Math.round((1 - coverage) * 100)}%` }]}>
      <Svg width="100%" height="100%" preserveAspectRatio="none">
        <Defs>
          <LinearGradient id={id} x1="0" y1="0" x2="0" y2="1">
            <Stop offset="0" stopColor={color} stopOpacity={0} />
            <Stop offset="0.45" stopColor={color} stopOpacity={strength * 0.55} />
            <Stop offset="1" stopColor={color} stopOpacity={strength} />
          </LinearGradient>
        </Defs>
        <Rect x="0" y="0" width="100%" height="100%" fill={`url(#${id})`} />
      </Svg>
    </View>
  );
}
