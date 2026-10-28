import { Text, View } from 'react-native';

import { glassPanel } from '../../theme/glass';
import { makeStyles } from '../../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../../theme/tokens';
import type { LeaderboardEntry } from '../../types';
import { formatCoins } from '../../utils/coins';
import { Avatar } from '../avatar/Avatar';
import { CoinAmount } from '../CoinAmount';
import { CoinAsset } from '../CoinAsset';

/**
 * A rank from 4 to 100: the rank as "#4" … "#100", the player's avatar in the green ring, name and
 * activity, and their earnings for the period (coin artwork + the server's figure) on the
 * right. The signed-in player's row is tinted.
 */
export function RankRow({ entry, isMe }: { entry: LeaderboardEntry; isMe: boolean }) {
  const styles = useStyles();

  return (
    <View
      style={[styles.row, isMe && styles.mine]}
      accessibilityLabel={`Rank ${entry.rank}, ${entry.username}${isMe ? ' (you)' : ''}, earned ${formatCoins(entry.score)} coins, ${entry.total_kills} kills in ${entry.tournaments_played} tournaments`}
    >
      <Text style={styles.rank} numberOfLines={1}>{`#${entry.rank}`}</Text>
      <Avatar username={entry.username} size={SIZE.avatarRow - 8} ring="ranked" />
      <View style={styles.text}>
        <Text style={styles.name} numberOfLines={1}>
          {entry.username}
          {isMe ? <Text style={styles.you}> · you</Text> : null}
        </Text>
        <View style={styles.metaRow}>
          <Text style={styles.meta} numberOfLines={1}>
            {entry.total_kills} {entry.total_kills === 1 ? 'kill' : 'kills'} · {entry.tournaments_played} played
          </Text>
          {entry.prize !== null ? (
            <View style={styles.prize}>
              <Text style={styles.meta}>· Reward</Text>
              <CoinAsset size={11} />
              <Text style={styles.meta}>{formatCoins(entry.prize)}</Text>
            </View>
          ) : null}
        </View>
      </View>
      <CoinAmount value={entry.score} size={16} textStyle={styles.score} style={styles.scoreRow} />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: {
    ...glassPanel(c),
    flexDirection: 'row',
    alignItems: 'center',
    gap: SPACE.xs,
    minHeight: 60,
    paddingVertical: SPACE.xxs,
    paddingLeft: SPACE.xs,
    paddingRight: SPACE.sm,
    borderRadius: RADIUS.md,
  },
  mine: { backgroundColor: c.brandSoft, borderColor: c.ring },
  rank: { ...TYPE.bodyStrong, fontSize: 14, color: c.fg2, width: 38, textAlign: 'center', fontVariant: ['tabular-nums'] },
  text: { flex: 1, minWidth: 0 },
  name: { ...TYPE.bodyStrong, color: c.fg },
  you: { color: c.brandText },
  metaRow: { flexDirection: 'row', alignItems: 'center', gap: 4, flexWrap: 'wrap' },
  meta: { ...TYPE.meta, color: c.fg3 },
  prize: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  scoreRow: { gap: 5 },
  score: { ...TYPE.bodyStrong, fontSize: 15, color: c.fg },
}));
