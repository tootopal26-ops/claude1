import { Text, View, type StyleProp, type TextStyle, type ViewStyle } from 'react-native';

import { makeStyles } from '../theme/ThemeContext';
import { CoinAsset } from './CoinAsset';

type CoinAmountProps = {
  value: string | number | null | undefined;
  size?: number;
  /** Optional trailing word (e.g. "coins"), shown muted after the number. */
  suffix?: string;
  textStyle?: StyleProp<TextStyle>;
  style?: StyleProp<ViewStyle>;
};

/**
 * An amount of coins: the coin artwork (always via <CoinAsset>) followed by
 * the number. Every coin amount in the app goes through this component.
 */
export function CoinAmount({ value, size = 16, suffix, textStyle, style }: CoinAmountProps) {
  const styles = useStyles();

  return (
    <View style={[styles.row, style]}>
      <CoinAsset size={size} />
      <Text style={[styles.value, textStyle]}>{value ?? '—'}</Text>
      {suffix ? <Text style={styles.suffix}>{suffix}</Text> : null}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  value: { color: c.fg, fontSize: 15, fontWeight: '700' },
  suffix: { color: c.fg3, fontSize: 13, fontWeight: '600' },
}));
