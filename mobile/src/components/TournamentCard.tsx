import { Link } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import { makeStyles } from '../theme/ThemeContext';
import type { Tournament } from '../types';
import { CoinAmount } from './CoinAmount';
import { StatusChip } from './StatusChip';

/**
 * One tournament in a list: title, mode and fill, entry fee and whether the
 * player is already in. Shared by the home list and the per-game list.
 */
export function TournamentCard({ item }: { item: Tournament }) {
  const styles = useStyles();
  const fill = item.total_slots > 0 ? Math.min(100, (item.joined_slots / item.total_slots) * 100) : 0;

  return (
    <Link href={`/tournament/${item.id}`} asChild>
      <Pressable style={({ pressed }) => [styles.card, pressed && styles.cardPressed]}>
        <Text style={styles.title}>{item.title}</Text>
        <Text style={styles.meta}>
          {item.mode.toUpperCase()} · {item.joined_slots}/{item.total_slots} joined
        </Text>
        <View style={styles.track}>
          <View style={[styles.fill, { width: `${fill}%` }]} />
        </View>
        <View style={styles.footer}>
          {Number(item.entry_fee) === 0 ? (
            <StatusChip tone="success" label="Free entry" />
          ) : (
            <View style={styles.feeRow}>
              <Text style={styles.meta}>Entry fee:</Text>
              <CoinAmount value={item.entry_fee} suffix="coins" />
            </View>
          )}
          {item.already_joined && <StatusChip tone="brand" label="You're in" />}
        </View>
      </Pressable>
    </Link>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  card: {
    backgroundColor: c.surface,
    borderRadius: 16,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: c.border,
  },
  cardPressed: { backgroundColor: c.surface2, borderColor: c.borderStrong },
  title: { color: c.fg, fontSize: 18, fontWeight: '700', marginBottom: 4 },
  meta: { color: c.fg2, fontSize: 13 },
  track: { height: 4, borderRadius: 2, backgroundColor: c.surface3, marginTop: 10, overflow: 'hidden' },
  fill: { height: 4, borderRadius: 2, backgroundColor: c.brand },
  footer: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 12, gap: 8 },
  feeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
}));
