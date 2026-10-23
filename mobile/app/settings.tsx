import { Pressable, ScrollView, Text, View } from 'react-native';

import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { COLOR_THEMES, FONT_THEMES, type ColorThemeKey, type FontThemeKey } from '../src/theme/tokens';

export default function SettingsScreen() {
  const { colorThemeKey, fontThemeKey, colors, setColorTheme, setFontTheme } = useTheme();
  const styles = useStyles();

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.sectionTitle}>Color theme</Text>
      {(Object.keys(COLOR_THEMES) as ColorThemeKey[]).map((key) => (
        <Pressable
          key={key}
          onPress={() => setColorTheme(key)}
          style={[styles.option, colorThemeKey === key && styles.optionSelected]}
          accessibilityRole="radio"
          accessibilityState={{ selected: colorThemeKey === key }}
        >
          <View style={[styles.swatch, { backgroundColor: COLOR_THEMES[key].brand, borderColor: COLOR_THEMES[key].surface3 }]} />
          <Text style={styles.optionLabel}>{COLOR_THEMES[key].label}</Text>
          {colorThemeKey === key && <Icon name="check" size={16} color={colors.brandText} />}
        </Pressable>
      ))}

      <Text style={[styles.sectionTitle, styles.sectionSpacer]}>Font theme</Text>
      {(Object.keys(FONT_THEMES) as FontThemeKey[]).map((key) => (
        <Pressable
          key={key}
          onPress={() => setFontTheme(key)}
          style={[styles.option, fontThemeKey === key && styles.optionSelected]}
          accessibilityRole="radio"
          accessibilityState={{ selected: fontThemeKey === key }}
        >
          <Text style={[styles.optionLabel, { fontFamily: FONT_THEMES[key].fontFamily }]}>{FONT_THEMES[key].label}</Text>
          {fontThemeKey === key && <Icon name="check" size={16} color={colors.brandText} />}
        </Pressable>
      ))}
    </ScrollView>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1 },
  content: { padding: 16, paddingBottom: 32 },
  sectionTitle: { color: c.fg3, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.6, marginBottom: 8 },
  sectionSpacer: { marginTop: 24 },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    minHeight: 48,
    borderWidth: 1,
    borderColor: c.border,
    backgroundColor: c.surface,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    marginBottom: 8,
  },
  optionSelected: { borderColor: c.brand, backgroundColor: c.brandSoft },
  swatch: { width: 20, height: 20, borderRadius: 10, borderWidth: 2 },
  optionLabel: { flex: 1, color: c.fg, fontSize: 15, fontWeight: '600' },
}));
