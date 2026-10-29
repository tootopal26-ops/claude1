import { useState } from 'react';
import { StyleSheet, Text, View, type StyleProp, type TextStyle, type ViewStyle } from 'react-native';

import { Artwork, type ArtworkName } from '../../theme/artwork';
import { makeStyles } from '../../theme/ThemeContext';
import { TYPE } from '../../theme/tokens';
import { formatCoins } from '../../utils/coins';
import { CoinAmount } from '../CoinAmount';

export type TournamentMetricKind = 'prizePool' | 'perKill' | 'entryFee';

const METRICS: Record<TournamentMetricKind, { artwork: ArtworkName; label: string }> = {
  prizePool: { artwork: 'prizePool', label: 'Prize Pool' },
  perKill: { artwork: 'perKill', label: 'Per Kill' },
  entryFee: { artwork: 'entryFee', label: 'Entry Fee' },
};

/** Average advance of a bold digit or separator, as a share of the font size. */
const DIGIT_WIDTH = 0.62;
const COIN_GAP = 4;
const MIN_FONT_SIZE = 9;

type Props = {
  kind: TournamentMetricKind;
  /** The tournament's own amount in coins; null for a free entry. */
  value: string | null;
  /** The box the icon is fitted into (ARTWORK_SIZE.metric on cards, .metricDetail on the details screen). */
  badgeSize: number;
  coinSize?: number;
  valueStyle?: StyleProp<TextStyle>;
  style?: StyleProp<ViewStyle>;
};

/**
 * One of a tournament's three money figures — prize pool, per-kill reward or entry fee —
 * shown as the supplied icon (trophy / skull / wallet, no text baked in) with the label and
 * amount drawn by the app underneath. A long amount gets a smaller font sized to the tile's
 * measured width, so it never wraps or runs out of the tile.
 */
export function TournamentMetric({ kind, value, badgeSize, coinSize = 14, valueStyle, style }: Props) {
  const styles = useStyles();
  const [room, setRoom] = useState(0);
  const { artwork, label } = METRICS[kind];
  const text = value === null ? 'Free' : formatCoins(value);
  const spoken = value === null ? 'Free' : `${text} coins`;

  const baseSize = StyleSheet.flatten([styles.value, valueStyle])?.fontSize ?? TYPE.bodyStrong.fontSize;
  const textRoom = room - (value === null ? 0 : coinSize + COIN_GAP);
  const fontSize = room > 0 ? Math.max(MIN_FONT_SIZE, Math.min(baseSize, textRoom / (text.length * DIGIT_WIDTH))) : baseSize;
  const fitted = fontSize < baseSize ? { fontSize, lineHeight: Math.round(fontSize * 1.4) } : null;

  return (
    <View style={[styles.metric, style]} accessible accessibilityLabel={`${label}: ${spoken}`}>
      <Artwork name={artwork} size={badgeSize} />
      <Text style={styles.label} numberOfLines={1}>
        {label}
      </Text>
      <View style={styles.valueRow} onLayout={(event) => setRoom(event.nativeEvent.layout.width)}>
        {value === null ? (
          <Text style={[styles.value, valueStyle, fitted, styles.free]} numberOfLines={1}>
            Free
          </Text>
        ) : (
          <CoinAmount value={value} size={coinSize} textStyle={[styles.value, valueStyle, fitted]} style={styles.amount} singleLine />
        )}
      </View>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  metric: { alignItems: 'center', gap: 2 },
  label: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.4 },
  valueRow: { alignSelf: 'stretch', alignItems: 'center' },
  amount: { gap: COIN_GAP, maxWidth: '100%' },
  value: { ...TYPE.bodyStrong, color: c.fg, flexShrink: 1 },
  free: { color: s.success },
}));
