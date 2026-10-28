import { useEffect, useRef } from 'react';
import { Animated, Text, View } from 'react-native';

import { Artwork, ARTWORK_SIZE } from '../../theme/artwork';
import { DURATION, EASE, useReducedMotion } from '../../theme/motion';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import { ProgressBar } from '../visual/ProgressBar';

type Props = {
  earned: number;
  required: number;
  /** Shown under the count, e.g. why the last ad didn't add a ticket. */
  hint?: string | null;
};

/**
 * A free tournament's rewarded-ad tickets: the supplied ticket artwork, the server's count
 * ("1/3"), a short bar, and a lock that fades away once every ticket is earned. The count
 * only ever changes to a value the server returned after verifying an ad.
 */
export function TicketProgress({ earned, required, hint }: Props) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const reduced = useReducedMotion();
  const complete = earned >= required;
  const lock = useRef(new Animated.Value(complete ? 0 : 1)).current;

  useEffect(() => {
    if (reduced) {
      lock.setValue(complete ? 0 : 1);
      return;
    }
    Animated.timing(lock, { toValue: complete ? 0 : 1, duration: DURATION.content, easing: EASE.out, useNativeDriver: true }).start();
  }, [complete, reduced, lock]);

  return (
    <View style={styles.box} accessibilityLabel={`Tickets ${earned} of ${required}${complete ? ', complete' : ''}`}>
      <View style={styles.head}>
        <Artwork name="redeem" size={ARTWORK_SIZE.inline + 6} />
        <Text style={styles.label}>Tickets</Text>
        <Text style={[styles.count, complete && { color: semanticColors.success }]}>
          {earned}/{required}
        </Text>
        <Animated.View style={{ opacity: lock, transform: [{ scale: lock.interpolate({ inputRange: [0, 1], outputRange: [0.4, 1] }) }] }}>
          <Icon name={complete ? 'unlock' : 'lock'} size={16} color={colors.fg2} />
        </Animated.View>
      </View>
      <ProgressBar value={required > 0 ? earned / required : 1} tone={complete ? 'success' : 'brand'} height={6} />
      <Text style={styles.hint}>
        {hint ?? (complete ? 'All tickets earned — you can join now.' : `Each ticket is one complete rewarded ad. Tap Join Now to watch the next one.`)}
      </Text>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  box: { backgroundColor: c.surface2, borderRadius: RADIUS.sm, padding: SPACE.xs, gap: 6 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  label: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.4, flex: 1 },
  count: { ...TYPE.bodyStrong, color: c.fg, fontVariant: ['tabular-nums'] },
  hint: { ...TYPE.meta, color: c.fg3 },
}));
