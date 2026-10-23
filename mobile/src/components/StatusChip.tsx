import { Text, View } from 'react-native';

import { makeStyles } from '../theme/ThemeContext';

export type ChipTone = 'success' | 'warning' | 'danger' | 'info' | 'brand' | 'neutral';

type StatusChipProps = {
  label: string;
  tone: ChipTone;
};

/** Small pill for statuses: semantic soft tint + matching text and dot. */
export function StatusChip({ label, tone }: StatusChipProps) {
  const styles = useStyles();

  return (
    <View style={[styles.chip, styles[`${tone}Chip`]]}>
      <View style={[styles.dot, styles[`${tone}Dot`]]} />
      <Text style={[styles.text, styles[`${tone}Text`]]}>{label}</Text>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  dot: { width: 6, height: 6, borderRadius: 3 },
  text: { fontSize: 12, fontWeight: '700' },
  successChip: { backgroundColor: s.successSoft },
  successDot: { backgroundColor: s.success },
  successText: { color: s.success },
  warningChip: { backgroundColor: s.warningSoft },
  warningDot: { backgroundColor: s.warning },
  warningText: { color: s.warning },
  dangerChip: { backgroundColor: s.dangerSoft },
  dangerDot: { backgroundColor: s.danger },
  dangerText: { color: s.danger },
  infoChip: { backgroundColor: s.infoSoft },
  infoDot: { backgroundColor: s.info },
  infoText: { color: s.info },
  brandChip: { backgroundColor: c.brandSoft },
  brandDot: { backgroundColor: c.brandText },
  brandText: { color: c.brandText },
  neutralChip: { backgroundColor: c.surface3 },
  neutralDot: { backgroundColor: c.fg3 },
  neutralText: { color: c.fg2 },
}));
