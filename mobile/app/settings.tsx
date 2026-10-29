import { Pressable, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAds, type AdConsent } from '../src/ads/AdsContext';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { Button } from '../src/components/Button';
import { SegmentedControl } from '../src/components/controls/SegmentedControl';
import { Switch } from '../src/components/controls/Switch';
import { useSound } from '../src/sound/SoundContext';
import { glassPanel } from '../src/theme/glass';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { COLOR_THEMES, FONT_THEMES, MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE, TYPE, type ColorThemeKey, type FontThemeKey } from '../src/theme/tokens';

type VolumeLevel = 'low' | 'medium' | 'high';
const VOLUME_VALUE: Record<VolumeLevel, number> = { low: 0.4, medium: 0.7, high: 1 };
function levelFromVolume(volume: number): VolumeLevel {
  if (volume <= 0.5) return 'low';
  if (volume <= 0.85) return 'medium';
  return 'high';
}

/**
 * Appearance, sound and ads. The tick / highlighted row / switch always reflects the saved
 * setting; choosing one applies it at once and remembers it for next time.
 */
export default function SettingsScreen() {
  const { colorThemeKey, fontThemeKey, colors, setColorTheme, setFontTheme } = useTheme();
  const styles = useStyles();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const { consent, setConsent } = useAds();
  const sound = useSound();
  const adChoices: { key: AdConsent; label: string }[] = [
    { key: 'personalised', label: 'Personalised ads' },
    { key: 'non_personalised', label: 'Non-personalised ads' },
  ];

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}>
        <View style={[styles.column, { width: contentWidth }]}>
          <Text style={styles.sectionTitle} accessibilityRole="header">
            Sound &amp; Haptics
          </Text>
          <View style={styles.group}>
            <View style={[styles.option, styles.divider]}>
              <View style={styles.rowText}>
                <Text style={styles.optionLabel}>UI sound effects</Text>
                <Text style={styles.rowHint}>Soft clicks, chimes and reward sounds across the app.</Text>
              </View>
              <Switch value={sound.soundEnabled} onValueChange={sound.setSoundEnabled} accessibilityLabel="UI sound effects" />
            </View>

            <View style={[styles.option, styles.divider, styles.volumeRow]}>
              <Text style={[styles.optionLabel, !sound.soundEnabled && styles.dimmed]}>Volume</Text>
              <View style={styles.volumeControl} pointerEvents={sound.soundEnabled ? 'auto' : 'none'}>
                <View style={!sound.soundEnabled && styles.dimmed}>
                  <SegmentedControl<VolumeLevel>
                    accessibilityLabel="Sound effects volume"
                    value={levelFromVolume(sound.volume)}
                    onChange={(level) => {
                      sound.setVolume(VOLUME_VALUE[level]);
                      sound.play('selection');
                    }}
                    options={[
                      { value: 'low', label: 'Low' },
                      { value: 'medium', label: 'Medium' },
                      { value: 'high', label: 'High' },
                    ]}
                  />
                </View>
              </View>
            </View>

            <View style={[styles.option, styles.divider]}>
              <View style={styles.rowText}>
                <Text style={[styles.optionLabel, !sound.hapticsSupported && styles.dimmed]}>Haptic feedback</Text>
                <Text style={styles.rowHint}>
                  {sound.hapticsSupported ? 'Gentle taps on presses, confirmations and rewards.' : 'This device does not support haptics.'}
                </Text>
              </View>
              <Switch value={sound.hapticsEnabled} onValueChange={sound.setHapticsEnabled} accessibilityLabel="Haptic feedback" disabled={!sound.hapticsSupported} />
            </View>

            <View style={styles.previewRow}>
              <Button label="Preview sound" variant="secondary" size="sm" icon="play" onPress={sound.preview} disabled={!sound.soundEnabled} pressSound="none" />
            </View>
          </View>
          <Text style={styles.note}>Sounds mix with your music and other apps and follow your phone&apos;s silent switch — they never interrupt a call, a video or an ad.</Text>

          <Text style={styles.sectionTitle} accessibilityRole="header">
            Colour theme
          </Text>
          <View style={styles.group} accessibilityRole="radiogroup">
            {(Object.keys(COLOR_THEMES) as ColorThemeKey[]).map((key, index, all) => {
              const selected = colorThemeKey === key;
              return (
                <Pressable
                  key={key}
                  onPress={() => {
                    sound.playSelection();
                    setColorTheme(key);
                  }}
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
                  onPress={() => {
                    sound.playSelection();
                    setFontTheme(key);
                  }}
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

          <Text style={styles.sectionTitle} accessibilityRole="header">
            Ads
          </Text>
          <View style={styles.group} accessibilityRole="radiogroup">
            {adChoices.map(({ key, label }, index, all) => {
              const selected = consent === key;
              return (
                <Pressable
                  key={key}
                  onPress={() => {
                    sound.playSelection();
                    setConsent(key);
                  }}
                  style={({ pressed }) => [styles.option, index < all.length - 1 && styles.divider, selected && styles.optionSelected, pressed && styles.pressed]}
                  accessibilityRole="radio"
                  aria-checked={selected}
                >
                  <Text style={styles.optionLabel}>{label}</Text>
                  {selected ? <Icon name="check" size={18} color={colors.brandText} /> : null}
                </Pressable>
              );
            })}
          </View>
          <Text style={styles.note}>Personalised ads use your device&apos;s advertising ID so our ad partners can match ads to your interests. Either way, ads stay labelled and rewards work the same.</Text>
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
  option: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, minHeight: SIZE.button + 4, paddingHorizontal: SPACE.md, paddingVertical: SPACE.xs },
  divider: { borderBottomWidth: 1, borderBottomColor: c.border },
  optionSelected: { backgroundColor: c.brandSoft },
  pressed: { backgroundColor: c.surface2 },
  swatch: { width: 22, height: 22, borderRadius: 11, borderWidth: 2 },
  optionLabel: { ...TYPE.bodyStrong, fontSize: 15, flex: 1, color: c.fg },
  rowText: { flex: 1, gap: 2 },
  rowHint: { ...TYPE.meta, color: c.fg3 },
  dimmed: { opacity: 0.5 },
  volumeRow: { flexDirection: 'column', alignItems: 'stretch', gap: SPACE.xs },
  volumeControl: { width: '100%' },
  previewRow: { paddingHorizontal: SPACE.md, paddingVertical: SPACE.sm, alignItems: 'flex-start' },
  note: { ...TYPE.meta, color: c.fg3, marginTop: -SPACE.xs, marginHorizontal: SPACE.xxs },
}));
