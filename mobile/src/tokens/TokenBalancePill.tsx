import { useEffect, useRef } from 'react';
import { Animated, Pressable, Text, View } from 'react-native';

import { TokenAmount } from '../components/TokenAmount';
import { glassFloat } from '../theme/glass';
import { EASE, SPRING, useReducedMotion } from '../theme/motion';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../theme/tokens';

type Props = {
  balance: number | null;
  onExchangePress: () => void;
};

/**
 * `[Token icon] [Total tokens] | [EXCHANGE]` — the Spin screen's top-right balance, always
 * the real backend figure from TokenContext (never a locally-guessed number), with a subtle
 * pop whenever it goes up (a spin credit or, were it ever to rise another way, anything else).
 */
export function TokenBalancePill({ balance, onExchangePress }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const reduced = useReducedMotion();
  const scale = useRef(new Animated.Value(1)).current;
  const previous = useRef(balance);

  useEffect(() => {
    if (!reduced && balance !== null && previous.current !== null && balance > previous.current) {
      scale.setValue(1);
      Animated.sequence([
        Animated.timing(scale, { toValue: 1.2, duration: 150, easing: EASE.out, useNativeDriver: true }),
        Animated.spring(scale, { ...SPRING, toValue: 1 }),
      ]).start();
    }
    previous.current = balance;
  }, [balance, reduced, scale]);

  return (
    <View style={styles.pill}>
      <Animated.View style={{ transform: [{ scale }] }}>
        <TokenAmount value={balance} size={18} textStyle={styles.amount} />
      </Animated.View>
      <View style={styles.divider} />
      <Pressable onPress={onExchangePress} accessibilityRole="button" accessibilityLabel="Exchange tokens for coins" hitSlop={8} style={styles.exchangeHit}>
        <Text style={styles.exchange}>EXCHANGE</Text>
      </Pressable>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  pill: { ...glassFloat(c), flexDirection: 'row', alignItems: 'center', borderRadius: RADIUS.pill, paddingLeft: SPACE.sm, paddingRight: SPACE.xs, height: 36, gap: SPACE.xs },
  amount: { ...TYPE.bodyStrong, color: c.fg },
  divider: { width: 1, height: 16, backgroundColor: c.borderStrong },
  exchangeHit: { minHeight: SIZE.touch - 20, paddingHorizontal: SPACE.xs, alignItems: 'center', justifyContent: 'center' },
  exchange: { ...TYPE.label, fontSize: 11, letterSpacing: 0.6, color: c.brandText },
}));
