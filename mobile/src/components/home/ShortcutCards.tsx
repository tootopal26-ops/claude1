import { Image, Text, View } from 'react-native';
import Svg, { Defs, LinearGradient, Rect, Stop } from 'react-native-svg';
import { useId } from 'react';

import { ARTWORK, type ArtworkName } from '../../theme/artwork';
import { glassPanel } from '../../theme/glass';
import { PRESS_SCALE } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { TournamentTab } from '../../types';
import { pushOnce } from '../../utils/navigation';
import { PressableScale } from '../interaction/PressableScale';
import { Skeleton } from '../states/States';

/**
 * The supplied status artwork (mobile/assets/status). The labels that were baked into the
 * original badges are not part of these files — the card writes the word as real text.
 */
const SHORTCUTS: { tab: TournamentTab; label: string; artwork: ArtworkName }[] = [
  { tab: 'ongoing', label: 'Ongoing', artwork: 'statusOngoing' },
  { tab: 'upcoming', label: 'Upcoming', artwork: 'statusUpcoming' },
  { tab: 'completed', label: 'Completed', artwork: 'statusCompleted' },
];

type Props = {
  /** The server's tab sizes; null while loading, 'error' when they could not be loaded. */
  counts: Record<TournamentTab, number> | null | 'error';
  /** Width of the row (the content column). */
  width: number;
};

const GAP = SPACE.xs;
/** The artwork area keeps one shape for all three cards so their heights match. */
const ART_ASPECT = 1.3;

/**
 * Three equal cards under the hero — Ongoing, Upcoming, Completed. Each shows the supplied
 * artwork (whole, at its own aspect ratio), and along its bottom a glass strip with the
 * status name and the server's count for that tab, right-aligned in green. Each opens the
 * tournament list on that tab.
 */
export function ShortcutCards({ counts, width }: Props) {
  const styles = useStyles();
  const cardWidth = Math.floor((width - GAP * 2) / 3);
  const artBox = { width: cardWidth - SPACE.xs * 2, height: Math.round((cardWidth - SPACE.xs * 2) / ART_ASPECT) };

  return (
    <View style={styles.row}>
      {SHORTCUTS.map((shortcut) => (
        <Shortcut
          key={shortcut.tab}
          {...shortcut}
          width={cardWidth}
          artBox={artBox}
          count={counts === null ? null : counts === 'error' ? undefined : counts[shortcut.tab]}
        />
      ))}
    </View>
  );
}

function Shortcut({ tab, label, artwork, count, width, artBox }: (typeof SHORTCUTS)[number] & { count: number | null | undefined; width: number; artBox: { width: number; height: number } }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const gradientId = `mm-strip-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
  const countText = count === undefined ? '—' : count === null ? null : String(count);

  // Fit the artwork inside its box by its own aspect ratio — never stretched or cropped.
  const { aspect } = ARTWORK[artwork];
  const fitted = artBox.width / artBox.height > aspect ? { width: Math.round(artBox.height * aspect), height: artBox.height } : { width: artBox.width, height: Math.round(artBox.width / aspect) };

  return (
    <PressableScale
      onPress={() => pushOnce(`/tournaments?status=${tab}`)}
      accessibilityRole="button"
      accessibilityLabel={countText === null ? label : `${label}, ${countText === '—' ? 'count unavailable' : countText}`}
      accessibilityHint={`Opens ${label.toLowerCase()} tournaments`}
      scaleTo={PRESS_SCALE.card}
      layoutStyle={{ width }}
      style={styles.card}
    >
      <View style={[styles.art, artBox]}>
        <Image source={ARTWORK[artwork].source} style={fitted} resizeMode="contain" />
      </View>

      <View style={styles.strip}>
        {/* Darker on the left, lighter on the right: a translucent glass strip. */}
        <Svg style={styles.stripFill} width="100%" height="100%" preserveAspectRatio="none">
          <Defs>
            <LinearGradient id={gradientId} x1="0" y1="0" x2="1" y2="0">
              <Stop offset="0" stopColor={colors.bg} stopOpacity={0.72} />
              <Stop offset="1" stopColor={colors.surface2} stopOpacity={0.38} />
            </LinearGradient>
          </Defs>
          <Rect x="0" y="0" width="100%" height="100%" fill={`url(#${gradientId})`} />
        </Svg>
        <Text style={styles.label} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8}>
          {label}
        </Text>
        {countText === null ? (
          <Skeleton width={18} height={16} />
        ) : (
          <Text style={[styles.count, countText === '—' && styles.countMissing]}>{countText}</Text>
        )}
      </View>
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  row: { flexDirection: 'row', gap: GAP },
  card: {
    ...glassPanel(c),
    borderRadius: RADIUS.lg,
    overflow: 'hidden',
    paddingTop: SPACE.xs,
    alignItems: 'center',
  },
  art: { alignItems: 'center', justifyContent: 'center', marginBottom: SPACE.xs },
  strip: {
    alignSelf: 'stretch',
    flexDirection: 'row',
    alignItems: 'center',
    gap: SPACE.xxs,
    paddingHorizontal: SPACE.xs,
    paddingVertical: 7,
    borderTopWidth: 1,
    borderTopColor: c.border,
  },
  stripFill: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  label: { ...TYPE.label, fontSize: 12, lineHeight: 16, color: c.fg, flex: 1, minWidth: 0 },
  count: { ...TYPE.bodyStrong, fontSize: 15, lineHeight: 18, color: s.success, fontVariant: ['tabular-nums'] },
  countMissing: { color: c.fg3 },
}));
