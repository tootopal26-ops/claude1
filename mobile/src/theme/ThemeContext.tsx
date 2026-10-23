import * as SecureStore from 'expo-secure-store';
import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { StyleSheet, type ImageStyle, type TextStyle, type ViewStyle } from 'react-native';

import {
  COLOR_THEMES,
  DEFAULT_COLOR_THEME,
  DEFAULT_FONT_THEME,
  FONT_THEMES,
  SEMANTIC_COLORS,
  type ColorTheme,
  type ColorThemeKey,
  type FontThemeKey,
  type SemanticColors,
} from './tokens';

const COLOR_THEME_KEY = 'minimax_theme_color';
const FONT_THEME_KEY = 'minimax_theme_font';

function isColorThemeKey(value: string | null): value is ColorThemeKey {
  return value !== null && value in COLOR_THEMES;
}

function isFontThemeKey(value: string | null): value is FontThemeKey {
  return value !== null && value in FONT_THEMES;
}

type ThemeContextValue = {
  colorThemeKey: ColorThemeKey;
  fontThemeKey: FontThemeKey;
  colors: ColorTheme;
  semanticColors: SemanticColors;
  fontFamily?: string;
  setColorTheme: (key: ColorThemeKey) => void;
  setFontTheme: (key: FontThemeKey) => void;
};

const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [colorThemeKey, setColorThemeKey] = useState<ColorThemeKey>(DEFAULT_COLOR_THEME);
  const [fontThemeKey, setFontThemeKey] = useState<FontThemeKey>(DEFAULT_FONT_THEME);

  useEffect(() => {
    (async () => {
      const [savedColor, savedFont] = await Promise.all([
        SecureStore.getItemAsync(COLOR_THEME_KEY),
        SecureStore.getItemAsync(FONT_THEME_KEY),
      ]);
      if (isColorThemeKey(savedColor)) setColorThemeKey(savedColor);
      if (isFontThemeKey(savedFont)) setFontThemeKey(savedFont);
    })();
  }, []);

  const value = useMemo<ThemeContextValue>(
    () => ({
      colorThemeKey,
      fontThemeKey,
      colors: COLOR_THEMES[colorThemeKey],
      semanticColors: SEMANTIC_COLORS,
      fontFamily: FONT_THEMES[fontThemeKey].fontFamily,
      setColorTheme(key) {
        setColorThemeKey(key);
        void SecureStore.setItemAsync(COLOR_THEME_KEY, key);
      },
      setFontTheme(key) {
        setFontThemeKey(key);
        void SecureStore.setItemAsync(FONT_THEME_KEY, key);
      },
    }),
    [colorThemeKey, fontThemeKey]
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext);
  if (!context) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }
  return context;
}

export type ThemeTokens = {
  colors: ColorTheme;
  semantic: SemanticColors;
};

/**
 * Themed replacement for a module-level StyleSheet.create: declare the
 * stylesheet once at module scope as a function of the active tokens, then
 * call the returned hook inside the component. The sheet is rebuilt only
 * when the selected theme changes, so every screen follows the Settings
 * theme picker instead of baking colors in at load time.
 *
 *   const useStyles = makeStyles(({ colors: c }) => ({ card: { backgroundColor: c.surface } }));
 *   // in the component: const styles = useStyles();
 */
type NamedStyles = Record<string, ViewStyle | TextStyle | ImageStyle>;

export function makeStyles<T extends NamedStyles>(factory: (tokens: ThemeTokens) => T & NamedStyles): () => Readonly<T> {
  return function useStyles() {
    const { colors, semanticColors } = useTheme();

    return useMemo(() => StyleSheet.create(factory({ colors, semantic: semanticColors })), [colors, semanticColors]);
  };
}
