import { useEffect, useRef } from 'react';
import { Animated, Text, View, type DimensionValue, type StyleProp, type ViewStyle } from 'react-native';

import { DURATION, EASE, useReducedMotion } from '../../theme/motion';
import { Icon, type IconName } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import { Button } from '../Button';

/**
 * A placeholder block shaped like the content it stands in for, with a restrained,
 * slow opacity pulse (static under reduced motion). It stops when unmounted.
 */
export function Skeleton({ width = '100%', height, radius = RADIUS.sm, style }: { width?: DimensionValue; height: number; radius?: number; style?: StyleProp<ViewStyle> }) {
  const { colors } = useTheme();
  const reduced = useReducedMotion();
  const opacity = useRef(new Animated.Value(0.55)).current;

  useEffect(() => {
    if (reduced) return;
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(opacity, { toValue: 1, duration: 700, easing: EASE.inOut, useNativeDriver: true }),
        Animated.timing(opacity, { toValue: 0.55, duration: 700, easing: EASE.inOut, useNativeDriver: true }),
      ]),
    );
    loop.start();

    return () => loop.stop();
  }, [reduced, opacity]);

  return <Animated.View style={[{ width, height, borderRadius: radius, backgroundColor: colors.surface3, opacity }, style]} />;
}

type StateProps = {
  icon?: IconName;
  title: string;
  message?: string;
  actionLabel?: string;
  onAction?: () => void;
  compact?: boolean;
};

/** Nothing to show yet: what is missing and, when there is one, what to do next. */
export function EmptyState({ icon = 'info', title, message, actionLabel, onAction, compact }: StateProps) {
  const styles = useStyles();
  const { colors } = useTheme();

  return (
    <View style={[styles.box, compact && styles.compact]}>
      <View style={styles.iconWrap}>
        <Icon name={icon} size={22} color={colors.fg3} accent={colors.brandText} />
      </View>
      <Text style={styles.title}>{title}</Text>
      {message ? <Text style={styles.message}>{message}</Text> : null}
      {actionLabel && onAction ? <Button label={actionLabel} variant="secondary" size="sm" onPress={onAction} style={styles.action} /> : null}
    </View>
  );
}

/** A failed load: a plain message and a working Retry — never a stack trace, never a made-up value. */
export function ErrorState({ title = "Couldn't load this", message = 'Check your connection and try again.', onRetry, compact }: { title?: string; message?: string; onRetry: () => void; compact?: boolean }) {
  const styles = useStyles();
  const { semanticColors } = useTheme();

  return (
    <View style={[styles.box, compact && styles.compact]} accessibilityRole="alert">
      <View style={styles.iconWrap}>
        <Icon name="wifi-off" size={22} color={semanticColors.danger} />
      </View>
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.message}>{message}</Text>
      <Button label="Try again" variant="secondary" size="sm" icon="refresh" onPress={onRetry} style={styles.action} />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  box: { alignItems: 'center', paddingVertical: SPACE.xxl, paddingHorizontal: SPACE.xl, gap: SPACE.xs },
  compact: { paddingVertical: SPACE.xl },
  iconWrap: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: SPACE.xxs,
  },
  title: { ...TYPE.cardTitle, color: c.fg, textAlign: 'center' },
  message: { ...TYPE.body, color: c.fg2, textAlign: 'center', maxWidth: 300 },
  action: { marginTop: SPACE.xs, minWidth: 140 },
}));
