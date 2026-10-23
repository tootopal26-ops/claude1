import { Text, View } from 'react-native';

import { makeStyles } from '../theme/ThemeContext';

type NoticeProps = {
  tone: 'danger' | 'success' | 'warning' | 'info';
  children: string;
};

/** Inline message (errors, confirmations) in a semantic soft tint. */
export function Notice({ tone, children }: NoticeProps) {
  const styles = useStyles();

  return (
    <View style={[styles.box, styles[`${tone}Box`]]} accessibilityRole={tone === 'danger' ? 'alert' : undefined}>
      <Text style={[styles.text, styles[`${tone}Text`]]}>{children}</Text>
    </View>
  );
}

const useStyles = makeStyles(({ semantic: s }) => ({
  box: { borderRadius: 12, paddingHorizontal: 14, paddingVertical: 10 },
  text: { fontSize: 13, fontWeight: '600', lineHeight: 18 },
  dangerBox: { backgroundColor: s.dangerSoft },
  dangerText: { color: s.danger },
  successBox: { backgroundColor: s.successSoft },
  successText: { color: s.success },
  warningBox: { backgroundColor: s.warningSoft },
  warningText: { color: s.warning },
  infoBox: { backgroundColor: s.infoSoft },
  infoText: { color: s.info },
}));
