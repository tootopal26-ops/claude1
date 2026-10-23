import type { ViewStyle } from 'react-native';

import type { ColorTheme } from './tokens';

/**
 * The shared glass recipe for the Android app. It mirrors the --mm-glass-* tokens in
 * backend/resources/css/themes.css (a backend test keeps the numbers equal), so a card looks
 * the same here as in the Admin and Staff panels: a translucent surface, a hairline border,
 * and a faint brand glow behind the screen that the translucency lets through.
 *
 * There is no backdrop blur on Android in this build, so layers that overlap other content
 * (dialogs, menus) use the solid fallback the design system defines for platforms that cannot
 * blur — a stable surface, never a half-transparent one with content ghosting through it.
 */
export const GLASS = {
  /** Share of the surface colour a static card keeps (rest shows the glow). */
  panel: 0.88,
  /** Brand glow strength behind a screen: main and secondary blob. */
  glow: 0.14,
  glowSoft: 0.08,
  /** Black-ish scrims laid over pictures so white text stays readable. */
  scrimBar: 0.82,
  scrimChip: 0.72,
} as const;

/** `#rgb` / `#rrggbb` token at the given opacity, as an rgba() string React Native understands. */
export function withOpacity(color: string, opacity: number): string {
  const hex = color.replace('#', '');
  const full = hex.length === 3 ? hex.replace(/(.)/g, '$1$1') : hex;
  const channel = (offset: number) => parseInt(full.slice(offset, offset + 2), 16);

  return `rgba(${channel(0)}, ${channel(2)}, ${channel(4)}, ${opacity})`;
}

/** A static card: translucent surface and a hairline border. */
export function glassPanel(colors: ColorTheme, tone: 'surface' | 'surface2' = 'surface'): ViewStyle {
  return {
    backgroundColor: withOpacity(colors[tone], GLASS.panel),
    borderWidth: 1,
    borderColor: colors.borderStrong,
  };
}

/** A dialog or menu sheet: solid (no blur available), with the same hairline border. */
export function glassFloat(colors: ColorTheme): ViewStyle {
  return {
    backgroundColor: colors.surface3,
    borderWidth: 1,
    borderColor: colors.borderStrong,
  };
}

/** A translucent dark layer over a picture (title bar, page indicator). */
export function glassScrim(color: string, kind: 'bar' | 'chip'): string {
  return withOpacity(color, kind === 'bar' ? GLASS.scrimBar : GLASS.scrimChip);
}
