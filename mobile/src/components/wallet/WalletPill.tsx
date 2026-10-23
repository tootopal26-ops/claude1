import { ActivityIndicator, Text, View } from 'react-native';

import { PRESS_SCALE } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../../theme/tokens';
import { formatCoins } from '../../utils/coins';
import { pushOnce } from '../../utils/navigation';
import { useWallet } from '../../wallet/WalletContext';
import { CoinAsset } from '../CoinAsset';
import { PressableScale } from '../interaction/PressableScale';
import { Skeleton } from '../states/States';

/**
 * The balance in the Home header: the coin artwork and the player's total balance, read
 * from the one shared wallet (the server's figure, never a local copy). It keeps the last
 * number while refreshing, marks a figure that could not be refreshed, and always opens
 * the Wallet.
 */
export function WalletPill() {
  const styles = useStyles();
  const { colors } = useTheme();
  const { wallet, loading, refreshing, failed } = useWallet();

  if (loading) {
    return <Skeleton width={96} height={SIZE.touch - 6} radius={RADIUS.pill} />;
  }

  const total = wallet ? formatCoins(wallet.total_balance) : null;
  const label = total
    ? `Wallet balance ${total} coins${failed ? ', may be out of date' : ''}. Opens your wallet.`
    : 'Wallet balance unavailable. Opens your wallet.';

  return (
    <PressableScale onPress={() => pushOnce('/wallet')} accessibilityRole="button" accessibilityLabel={label} scaleTo={PRESS_SCALE.button} hitSlop={4}>
      <View style={styles.pill}>
        <CoinAsset size={SIZE.coinPill} />
        <Text style={styles.value}>{total ?? '—'}</Text>
        {refreshing && wallet ? <ActivityIndicator size="small" color={colors.fg3} style={styles.spinner} /> : null}
        {failed && wallet && !refreshing ? <View style={styles.staleDot} /> : null}
      </View>
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: SIZE.touch - 6,
    paddingLeft: SPACE.xs,
    paddingRight: SPACE.sm,
    borderRadius: RADIUS.pill,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.borderStrong,
  },
  // Never truncated: the pill takes the room the figure needs and the greeting gives way.
  value: { ...TYPE.bodyStrong, fontSize: 15, color: c.fg },
  spinner: { transform: [{ scale: 0.7 }], marginLeft: -2 },
  staleDot: { width: 6, height: 6, borderRadius: 3, backgroundColor: s.warning },
}));
