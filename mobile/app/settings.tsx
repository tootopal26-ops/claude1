import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useTheme } from '../src/theme/ThemeContext';
import { COLOR_THEMES, FONT_THEMES, type ColorThemeKey, type FontThemeKey } from '../src/theme/tokens';

export default function SettingsScreen() {
  const { colorThemeKey, fontThemeKey, colors, setColorTheme, setFontTheme } = useTheme();

  return (
    <ScrollView style={[styles.container, { backgroundColor: colors.background }]} contentContainerStyle={styles.content}>
      <Text style={[styles.sectionTitle, { color: colors.textMuted }]}>Color theme</Text>
      {(Object.keys(COLOR_THEMES) as ColorThemeKey[]).map((key) => (
        <Pressable
          key={key}
          onPress={() => setColorTheme(key)}
          style={[
            styles.option,
            { borderColor: colors.surfaceBorder, backgroundColor: colors.surface },
            colorThemeKey === key && { borderColor: colors.primary, borderWidth: 2 },
          ]}
        >
          <View style={[styles.swatch, { backgroundColor: COLOR_THEMES[key].primary }]} />
          <Text style={[styles.optionLabel, { color: colors.text }]}>{COLOR_THEMES[key].label}</Text>
          {colorThemeKey === key && <Text style={{ color: colors.primary }}>✓</Text>}
        </Pressable>
      ))}

      <Text style={[styles.sectionTitle, { color: colors.textMuted, marginTop: 24 }]}>Font theme</Text>
      {(Object.keys(FONT_THEMES) as FontThemeKey[]).map((key) => (
        <Pressable
          key={key}
          onPress={() => setFontTheme(key)}
          style={[
            styles.option,
            { borderColor: colors.surfaceBorder, backgroundColor: colors.surface },
            fontThemeKey === key && { borderColor: colors.primary, borderWidth: 2 },
          ]}
        >
          <Text style={[styles.optionLabel, { color: colors.text, fontFamily: FONT_THEMES[key].fontFamily }]}>{FONT_THEMES[key].label}</Text>
          {fontThemeKey === key && <Text style={{ color: colors.primary }}>✓</Text>}
        </Pressable>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  content: { padding: 16, paddingBottom: 32 },
  sectionTitle: { fontSize: 13, fontWeight: '700', textTransform: 'uppercase', marginBottom: 8 },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    borderWidth: 1,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    marginBottom: 8,
  },
  swatch: { width: 20, height: 20, borderRadius: 10 },
  optionLabel: { flex: 1, fontSize: 15, fontWeight: '600' },
});
