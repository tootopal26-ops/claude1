import { useEffect, useRef } from 'react';
import { ActivityIndicator, Animated, Text, View } from 'react-native';

import { DURATION, EASE } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { SPACE, TYPE } from '../../theme/tokens';
import { AmbientGlow } from '../AmbientGlow';
import { BrandLogo } from '../BrandLogo';
import { Button } from '../Button';

type Props = { error?: string | null; onRetry?: () => void };

/**
 * What shows while the app checks the saved sign-in: the real Mini Max logo at the native
 * splash's size and colour (so the hand-over is seamless) and a quiet progress indicator.
 * If the server cannot be reached it says so and offers Retry — it never signs the player
 * out for a network problem and never lets them past without a confirmed session.
 */
export function BootScreen({ error, onRetry }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const fade = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    Animated.timing(fade, { toValue: 1, duration: DURATION.content, easing: EASE.out, useNativeDriver: true }).start();
  }, [fade]);

  return (
    <View style={styles.root}>
      <AmbientGlow />
      <BrandLogo width={220} />
      <Animated.View style={[styles.below, { opacity: fade }]}>
        {error ? (
          <>
            <Text style={styles.error} accessibilityRole="alert">
              {error}
            </Text>
            {onRetry ? <Button label="Try again" icon="refresh" onPress={onRetry} style={styles.retry} /> : null}
          </>
        ) : (
          <ActivityIndicator color={colors.brandText} accessibilityLabel="Loading" />
        )}
      </Animated.View>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  root: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: c.bg, padding: SPACE.xl },
  below: { minHeight: 96, alignItems: 'center', justifyContent: 'flex-start', marginTop: SPACE.xl, gap: SPACE.md },
  error: { ...TYPE.body, color: c.fg2, textAlign: 'center', maxWidth: 300 },
  retry: { minWidth: 160 },
}));
