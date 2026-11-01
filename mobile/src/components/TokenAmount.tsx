import { Text, View, type StyleProp, type TextStyle, type ViewStyle } from 'react-native';

import { makeStyles } from '../theme/ThemeContext';
import { TokenAsset } from './TokenAsset';

type TokenAmountProps = {
  value: number | null | undefined;
  size?: number;
  suffix?: string;
  textStyle?: StyleProp<TextStyle>;
  style?: StyleProp<ViewStyle>;
};

/** Tokens are always whole numbers (every spin and exchange tier is an integer) — just
 *  thousands-separated, never a decimal. Mirrors CoinAmount's shape for tokens. */
function formatTokens(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return '—';
  return Math.trunc(value).toLocaleString('en-IN');
}

/** A token amount: the token artwork (always via <TokenAsset>) followed by the number. */
export function TokenAmount({ value, size = 16, suffix, textStyle, style }: TokenAmountProps) {
  const styles = useStyles();

  return (
    <View style={[styles.row, style]}>
      <TokenAsset size={size} />
      <Text style={[styles.value, textStyle]}>{formatTokens(value)}</Text>
      {suffix ? <Text style={styles.suffix}>{suffix}</Text> : null}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  value: { color: c.fg, fontSize: 15, fontWeight: '700' },
  suffix: { color: c.fg3, fontSize: 13, fontWeight: '600' },
}));
