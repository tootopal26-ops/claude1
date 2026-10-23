import { Text, View } from 'react-native';

import { glassPanel } from '../../theme/glass';
import { makeStyles } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import { CoinAmount } from '../CoinAmount';
import { Skeleton } from '../states/States';

type Props = {
  label: string;
  /** A coin amount, a count, or null while loading. */
  value: { coins: string } | { count: number } | null;
  /** Shown instead of the value when it could not be loaded. */
  failed?: boolean;
};

/** One figure in the Profile stats row: label over the server's value. */
export function StatCard({ label, value, failed }: Props) {
  const styles = useStyles();

  return (
    <View style={styles.card}>
      {failed ? (
        <Text style={styles.value}>—</Text>
      ) : value === null ? (
        <Skeleton width={56} height={20} />
      ) : 'coins' in value ? (
        <CoinAmount value={value.coins} size={16} textStyle={styles.value} style={styles.coins} />
      ) : (
        <Text style={styles.value}>{value.count}</Text>
      )}
      <Text style={styles.label} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.85}>
        {label}
      </Text>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  card: { ...glassPanel(c), flex: 1, minWidth: 0, borderRadius: RADIUS.lg, paddingVertical: SPACE.sm, paddingHorizontal: SPACE.xs, alignItems: 'center', gap: SPACE.xxs, minHeight: 72, justifyContent: 'center' },
  coins: { gap: 4 },
  value: { ...TYPE.cardTitle, fontSize: 16, color: c.fg },
  label: { ...TYPE.label, fontSize: 12, color: c.fg3 },
}));
