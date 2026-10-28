import { View, type StyleProp, type ViewStyle } from 'react-native';

import { makeStyles } from '../../theme/ThemeContext';

/**
 * The red "unread" dot drawn over a bell. Shown only when the server reports unread
 * notifications — the bell artwork itself has no dot, so it never claims unread on its own.
 */
export function UnreadDot({ size = 10, style }: { size?: number; style?: StyleProp<ViewStyle> }) {
  const styles = useStyles();

  return <View style={[styles.dot, { width: size, height: size, borderRadius: size / 2 }, style]} accessibilityElementsHidden importantForAccessibility="no" />;
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  dot: { position: 'absolute', backgroundColor: s.dangerSolid, borderWidth: 2, borderColor: c.bg },
}));
