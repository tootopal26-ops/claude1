import { Image, Text, View, type ImageSourcePropType } from 'react-native';

import { glassPanel } from '../../theme/glass';
import { PRESS_SCALE } from '../../theme/motion';
import { makeStyles } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { TournamentTab } from '../../types';
import { pushOnce } from '../../utils/navigation';
import { PressableScale } from '../interaction/PressableScale';
import { Skeleton } from '../states/States';

/**
 * The supplied badge artwork (mobile/assets/badges, cut out of the user's
 * "Ongoing Upcoming Completed Gaming Badges" sheet). Each badge carries its own label.
 */
const BADGES: Record<TournamentTab, ImageSourcePropType> = {
  ongoing: require('../../../assets/badges/ongoing.png'),
  upcoming: require('../../../assets/badges/upcoming.png'),
  completed: require('../../../assets/badges/completed.png'),
};

const SHORTCUTS: { tab: TournamentTab; label: string }[] = [
  { tab: 'ongoing', label: 'Ongoing' },
  { tab: 'upcoming', label: 'Upcoming' },
  { tab: 'completed', label: 'Completed' },
];

type Props = {
  /** The server's tab sizes; null while loading, 'error' when they could not be loaded. */
  counts: Record<TournamentTab, number> | null | 'error';
  /** Width of the row (the content column). */
  width: number;
};

const GAP = SPACE.sm;
const PAD = SPACE.xs;
/** All three badges share one box (width : height), so the cards line up. */
const BADGE_ASPECT = 1.08;

/**
 * Three equal cards under the hero — Ongoing, Upcoming, Completed — each showing the
 * supplied badge and the server's count for that tab. Each opens the tournament list on it.
 */
export function ShortcutCards({ counts, width }: Props) {
  const styles = useStyles();
  // A require()d image keeps its intrinsic pixel size unless given both dimensions.
  const badgeWidth = Math.floor((width - GAP * 2) / 3) - PAD * 2;
  const badge = { width: badgeWidth, height: Math.round(badgeWidth / BADGE_ASPECT) };

  return (
    <View style={styles.row}>
      {SHORTCUTS.map((shortcut) => (
        <Shortcut key={shortcut.tab} {...shortcut} badge={badge} count={counts === null ? null : counts === 'error' ? undefined : counts[shortcut.tab]} />
      ))}
    </View>
  );
}

function Shortcut({ tab, label, count, badge }: (typeof SHORTCUTS)[number] & { count: number | null | undefined; badge: { width: number; height: number } }) {
  const styles = useStyles();
  const countText = count === undefined ? '—' : count === null ? null : String(count);

  return (
    <PressableScale
      onPress={() => pushOnce(`/tournaments?status=${tab}`)}
      accessibilityRole="button"
      accessibilityHint={`Opens ${label.toLowerCase()} tournaments`}
      scaleTo={PRESS_SCALE.card}
      layoutStyle={styles.layout}
      style={styles.card}
    >
      <Image source={BADGES[tab]} style={badge} resizeMode="contain" accessibilityLabel={label} />
      {countText === null ? (
        <Skeleton width={28} height={24} />
      ) : (
        <Text style={[styles.count, countText === '—' && styles.countMissing]}>{countText}</Text>
      )}
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: { flexDirection: 'row', gap: GAP },
  layout: { flex: 1 },
  card: {
    ...glassPanel(c),
    borderRadius: RADIUS.lg,
    paddingTop: SPACE.sm,
    paddingBottom: SPACE.xs,
    paddingHorizontal: PAD,
    alignItems: 'center',
    gap: SPACE.xxs,
  },
  count: { ...TYPE.section, fontSize: 20, lineHeight: 26, color: c.fg, fontVariant: ['tabular-nums'] },
  countMissing: { color: c.fg3 },
}));
