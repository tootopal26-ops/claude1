import { ActivityIndicator, Text, View } from 'react-native';

import { withOpacity } from '../../theme/glass';
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
 * The Home header's wallet tile: a compact rounded rectangle (not a capsule) with the coin
 * artwork and the player's total balance from the one shared wallet — the server's figure,
 * never a local copy. It sizes to the figure (which is never truncated), keeps the last
 * number while refreshing, marks a figure that could not be refreshed, and opens the Wallet.
 */
export function WalletPill() {
  const styles = useStyles();
  const { colors } = useTheme();
  const { wallet, loading, refreshing, failed } = useWallet();

  if (loading) {
    return <Skeleton width={96} height={SIZE.headerControl} radius={RADIUS.md} />;
  }

  const total = wallet ? formatCoins(wallet.total_balance) : null;
  const label = total
    ? `Wallet balance ${total} coins${failed ? ', may be out of date' : ''}. Opens your wallet.`
    : 'Wallet balance unavailable. Opens your wallet.';

  return (
    <PressableScale onPress={() => pushOnce('/wallet')} accessibilityRole="button" accessibilityLabel={label} scaleTo={PRESS_SCALE.button} hitSlop={4}>
      <View style={styles.tile}>
        <CoinAsset size={SIZE.coinPill} />
        <Text style={styles.value}>{total ?? '—'}</Text>
        {refreshing && wallet ? <ActivityIndicator size="small" color={colors.fg3} style={styles.spinner} /> : null}
        {failed && wallet && !refreshing ? <View style={styles.staleDot} /> : null}
      </View>
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  tile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: SPACE.xs,
    height: SIZE.headerControl,
    paddingHorizontal: SPACE.sm - 2,
    borderRadius: RADIUS.md,
    backgroundColor: withOpacity(c.surface2, 0.9),
    borderWidth: 1,
    borderColor: c.borderStrong,
  },
  // Never truncated: the tile takes the room the figure needs and the greeting gives way.
  value: { ...TYPE.bodyStrong, fontSize: 15, color: c.fg, fontVariant: ['tabular-nums'] },
  spinner: { transform: [{ scale: 0.7 }], marginLeft: -SPACE.xxs },
  staleDot: { width: 6, height: 6, borderRadius: 3, backgroundColor: s.warning },
}));
