import { Text, View } from 'react-native';

import { glassPanel, withOpacity } from '../../theme/glass';
import { PRESS_SCALE } from '../../theme/motion';
import { Icon, type IconName } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { TournamentTab } from '../../types';
import { pushOnce } from '../../utils/navigation';
import { PressableScale } from '../interaction/PressableScale';
import { Skeleton } from '../states/States';

const SHORTCUTS: { tab: TournamentTab; label: string; icon: IconName; tone: 'success' | 'info' | 'warning' }[] = [
  { tab: 'ongoing', label: 'Ongoing', icon: 'play', tone: 'success' },
  { tab: 'upcoming', label: 'Upcoming', icon: 'calendar', tone: 'info' },
  { tab: 'completed', label: 'Completed', icon: 'trophy', tone: 'warning' },
];

type Props = {
  /** The server's tab sizes; null while loading, 'error' when they could not be loaded. */
  counts: Record<TournamentTab, number> | null | 'error';
};

/**
 * Three equal cards under the hero: Ongoing, Upcoming and Completed tournaments, each with
 * its own colour and the server's count. Each opens the tournament list on that tab.
 */
export function ShortcutCards({ counts }: Props) {
  const styles = useStyles();

  return (
    <View style={styles.row}>
      {SHORTCUTS.map((shortcut) => (
        <Shortcut key={shortcut.tab} {...shortcut} count={counts === null ? null : counts === 'error' ? undefined : counts[shortcut.tab]} />
      ))}
    </View>
  );
}

function Shortcut({ tab, label, icon, tone, count }: (typeof SHORTCUTS)[number] & { count: number | null | undefined }) {
  const styles = useStyles();
  const { semanticColors } = useTheme();
  const color = semanticColors[tone];
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
      <View style={[styles.tile, { backgroundColor: withOpacity(color, 0.14), borderColor: withOpacity(color, 0.32) }]}>
        <Icon name={icon} size={20} color={color} accent={color} />
      </View>
      {countText === null ? <Skeleton width={26} height={20} /> : <Text style={styles.count}>{countText}</Text>}
      <Text style={styles.label} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.85}>
        {label}
      </Text>
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: { flexDirection: 'row', gap: SPACE.sm },
  layout: { flex: 1 },
  card: {
    ...glassPanel(c),
    borderRadius: RADIUS.lg,
    paddingVertical: SPACE.sm,
    paddingHorizontal: SPACE.xs,
    alignItems: 'center',
    gap: SPACE.xxs + 2,
    minHeight: 112,
    justifyContent: 'center',
  },
  tile: { width: 40, height: 40, borderRadius: RADIUS.md, borderWidth: 1, alignItems: 'center', justifyContent: 'center' },
  count: { ...TYPE.section, fontSize: 18, color: c.fg },
  label: { ...TYPE.label, fontSize: 12, color: c.fg2 },
}));
