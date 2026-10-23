import { Text, View } from 'react-native';

import { PRESS_SCALE } from '../../theme/motion';
import { Icon, type IconName } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { ICON_SIZE, RADIUS, SIZE, SPACE, TYPE } from '../../theme/tokens';
import { PressableScale } from '../interaction/PressableScale';

type Props = {
  icon: IconName;
  label: string;
  onPress: () => void;
  /** Short grey text before the chevron (for example the current theme). */
  detail?: string;
  tone?: 'default' | 'danger';
  last?: boolean;
};

/** One row of a settings-style menu: icon tile, label, optional detail, chevron. */
export function MenuRow({ icon, label, onPress, detail, tone = 'default', last }: Props) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const color = tone === 'danger' ? semanticColors.danger : colors.fg;

  return (
    <PressableScale onPress={onPress} accessibilityRole="button" accessibilityLabel={detail ? `${label}, ${detail}` : label} scaleTo={PRESS_SCALE.card} pressedStyle={styles.pressed}>
      <View style={[styles.row, !last && styles.divider]}>
        <View style={[styles.tile, tone === 'danger' && { backgroundColor: semanticColors.dangerSoft }]}>
          <Icon name={icon} size={ICON_SIZE.row} />
        </View>
        <Text style={[styles.label, { color }]} numberOfLines={1}>
          {label}
        </Text>
        {detail ? (
          <Text style={styles.detail} numberOfLines={1}>
            {detail}
          </Text>
        ) : null}
        {tone === 'danger' ? null : <Icon name="chevron-right" size={16} color={colors.fg3} />}
      </View>
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, minHeight: 56, paddingHorizontal: SPACE.sm },
  divider: { borderBottomWidth: 1, borderBottomColor: c.border },
  pressed: { backgroundColor: c.surface2 },
  tile: { width: SIZE.touch - 10, height: SIZE.touch - 10, borderRadius: RADIUS.sm, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' },
  label: { ...TYPE.bodyStrong, fontSize: 15, flex: 1 },
  detail: { ...TYPE.meta, color: c.fg3, maxWidth: 140 },
}));
