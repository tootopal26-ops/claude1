import { Text, View } from 'react-native';

import { Artwork, ARTWORK_SIZE, type ArtworkName } from '../../theme/artwork';
import { withOpacity } from '../../theme/glass';
import { PRESS_SCALE } from '../../theme/motion';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import { PressableScale } from '../interaction/PressableScale';
import { UnreadDot } from '../visual/UnreadDot';

type Props = {
  /** The supplied colourful artwork for this row (mobile/assets/icons). */
  artwork: ArtworkName;
  label: string;
  onPress: () => void;
  /** Short grey text before the chevron. */
  detail?: string;
  /** Shows the red unread dot on the artwork (used by Notifications). */
  badge?: boolean;
  tone?: 'default' | 'danger';
  /** Accessibility hint for what the row opens. */
  hint?: string;
};

/**
 * One Profile menu row: its own rounded glass card, the supplied artwork on the left
 * (fitted, never cropped), the title, an optional detail and a chevron. The whole row is
 * the touch target and gives a brief press response; it fires once per tap.
 */
export function MenuRow({ artwork, label, onPress, detail, badge, tone = 'default', hint }: Props) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const danger = tone === 'danger';

  return (
    <PressableScale
      sound="selection"
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={[label, badge ? 'unread notifications' : null, detail].filter(Boolean).join(', ')}
      accessibilityHint={hint}
      scaleTo={PRESS_SCALE.card}
      style={[styles.row, danger && styles.rowDanger]}
      pressedStyle={danger ? styles.pressedDanger : styles.pressed}
    >
      <View style={styles.art}>
        <Artwork name={artwork} size={ARTWORK_SIZE.menu} />
        {badge ? <UnreadDot style={styles.dot} /> : null}
      </View>
      <Text style={[styles.label, { color: danger ? semanticColors.danger : colors.fg }]} numberOfLines={1}>
        {label}
      </Text>
      {detail ? (
        <Text style={styles.detail} numberOfLines={1}>
          {detail}
        </Text>
      ) : null}
      {danger ? null : <Icon name="chevron-right" size={16} color={colors.fg3} />}
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: SPACE.sm,
    minHeight: 58,
    paddingHorizontal: SPACE.sm,
    borderRadius: RADIUS.lg,
    backgroundColor: withOpacity(c.surface, 0.72),
    borderWidth: 1,
    borderColor: c.border,
  },
  rowDanger: { borderColor: withOpacity(s.danger, 0.4), backgroundColor: withOpacity(s.dangerSolid, 0.1) },
  pressed: { backgroundColor: c.surface2 },
  pressedDanger: { backgroundColor: withOpacity(s.dangerSolid, 0.18) },
  art: { width: ARTWORK_SIZE.menu, height: ARTWORK_SIZE.menu },
  dot: { top: 0, right: 1 },
  label: { ...TYPE.bodyStrong, fontSize: 15, flex: 1 },
  detail: { ...TYPE.meta, color: c.fg3, maxWidth: 140 },
}));
