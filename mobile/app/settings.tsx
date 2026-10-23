import { Pressable, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AmbientGlow } from '../src/components/AmbientGlow';
import { glassPanel } from '../src/theme/glass';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { COLOR_THEMES, FONT_THEMES, MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE, TYPE, type ColorThemeKey, type FontThemeKey } from '../src/theme/tokens';

/**
 * Appearance: the seven colour themes and seven font themes. The tick and the highlighted
 * row always reflect the saved setting; choosing one applies it at once.
 */
export default function SettingsScreen() {
  const { colorThemeKey, fontThemeKey, colors, setColorTheme, setFontTheme } = useTheme();
  const styles = useStyles();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}>
        <View style={[styles.column, { width: contentWidth }]}>
          <Text style={styles.sectionTitle} accessibilityRole="header">
            Colour theme
          </Text>
          <View style={styles.group} accessibilityRole="radiogroup">
            {(Object.keys(COLOR_THEMES) as ColorThemeKey[]).map((key, index, all) => {
              const selected = colorThemeKey === key;
              return (
                <Pressable
                  key={key}
                  onPress={() => setColorTheme(key)}
                  style={({ pressed }) => [styles.option, index < all.length - 1 && styles.divider, selected && styles.optionSelected, pressed && styles.pressed]}
                  accessibilityRole="radio"
                  aria-checked={selected}
                >
                  <View style={[styles.swatch, { backgroundColor: COLOR_THEMES[key].brand, borderColor: COLOR_THEMES[key].surface3 }]} />
                  <Text style={styles.optionLabel}>{COLOR_THEMES[key].label}</Text>
                  {selected ? <Icon name="check" size={18} color={colors.brandText} /> : null}
                </Pressable>
              );
            })}
          </View>

          <Text style={styles.sectionTitle} accessibilityRole="header">
            Font theme
          </Text>
          <View style={styles.group} accessibilityRole="radiogroup">
            {(Object.keys(FONT_THEMES) as FontThemeKey[]).map((key, index, all) => {
              const selected = fontThemeKey === key;
              return (
                <Pressable
                  key={key}
                  onPress={() => setFontTheme(key)}
                  style={({ pressed }) => [styles.option, index < all.length - 1 && styles.divider, selected && styles.optionSelected, pressed && styles.pressed]}
                  accessibilityRole="radio"
                  aria-checked={selected}
                >
                  <Text style={[styles.optionLabel, { fontFamily: FONT_THEMES[key].fontFamily }]}>{FONT_THEMES[key].label}</Text>
                  {selected ? <Icon name="check" size={18} color={colors.brandText} /> : null}
                </Pressable>
              );
            })}
          </View>
        </View>
      </ScrollView>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.md },
  column: { gap: SPACE.sm },
  sectionTitle: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.8, marginTop: SPACE.xs, marginLeft: SPACE.xxs },
  group: { ...glassPanel(c), borderRadius: RADIUS.lg, overflow: 'hidden', marginBottom: SPACE.md },
  option: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, minHeight: SIZE.button + 4, paddingHorizontal: SPACE.md },
  divider: { borderBottomWidth: 1, borderBottomColor: c.border },
  optionSelected: { backgroundColor: c.brandSoft },
  pressed: { backgroundColor: c.surface2 },
  swatch: { width: 22, height: 22, borderRadius: 11, borderWidth: 2 },
  optionLabel: { ...TYPE.bodyStrong, fontSize: 15, flex: 1, color: c.fg },
}));
