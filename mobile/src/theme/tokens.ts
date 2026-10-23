import { Platform } from 'react-native';

/**
 * The 7 color themes and 7 font themes. The color tokens use the SAME names
 * and values as the web Admin/Staff UI (backend/resources/css/themes.css,
 * documented in docs/design-system.md): a neutral-dark surface hierarchy
 * with the brand color reserved for actions, selection, focus and links.
 * Keep the two in sync by hand if either side changes.
 *
 * Surface hierarchy, darkest to lightest:
 *   bg        screen canvas
 *   sidebar   navigation chrome (stack header)
 *   surface   cards and panels
 *   surface2  inputs, inactive chips/tabs, nested panels
 *   surface3  menus, dialogs, popovers
 *
 * Semantic colors (success/warning/danger/info) carry meaning independent of
 * the chosen theme, so they live outside ColorTheme and stay fixed across all
 * 7 — see SEMANTIC_COLORS below. This is the ONLY file that may contain raw
 * color values; screens and components read tokens via useTheme()/makeStyles.
 */
export type ColorThemeKey =
  | 'purple-glass'
  | 'midnight-emerald'
  | 'crimson-arena'
  | 'cyber-blue'
  | 'sunset-gold'
  | 'royal-indigo'
  | 'monochrome-steel';

export type FontThemeKey =
  | 'default-sans'
  | 'modern-grotesk'
  | 'rounded-friendly'
  | 'classic-serif'
  | 'technical-mono'
  | 'condensed-impact'
  | 'elegant-display';

export type ColorTheme = {
  label: string;
  bg: string;
  sidebar: string;
  surface: string;
  surface2: string;
  surface3: string;
  border: string;
  borderStrong: string;
  fg: string;
  fg2: string;
  fg3: string;
  brand: string;
  brandHover: string;
  brandPressed: string;
  brandSoft: string;
  brandText: string;
  onBrand: string;
  ring: string;
};

export const SEMANTIC_COLORS = {
  success: '#34d399',
  successSoft: 'rgba(52,211,153,0.12)',
  warning: '#fbbf24',
  warningSoft: 'rgba(251,191,36,0.12)',
  danger: '#f87171',
  dangerSoft: 'rgba(248,113,113,0.12)',
  dangerSolid: '#dc2626',
  onDanger: '#ffffff',
  info: '#60a5fa',
  infoSoft: 'rgba(96,165,250,0.12)',
  overlay: 'rgba(5,3,8,0.7)',
  // Leaderboard rank rings. Gold also marks first place elsewhere; ranks 4–100 use `success`.
  rankGold: '#ffc857',
  rankSilver: '#cdd3dc',
  rankBronze: '#c98b5e',
};

export type SemanticColors = typeof SEMANTIC_COLORS;

/**
 * Category colours for the filled icon set (src/theme/icons.tsx). Each family has a main
 * fill, a deeper shade for depth and a light tint for highlights. Purple icons (home,
 * wallet, redeem, tournaments) take the active theme's brand colours instead, so they
 * follow the Settings theme picker.
 */
export const ICON_COLORS = {
  gold: { main: '#fbbf24', deep: '#d97706', light: '#fde68a' },
  green: { main: '#22c55e', deep: '#15803d', light: '#bbf7d0' },
  blue: { main: '#3b82f6', deep: '#1d4ed8', light: '#dbeafe' },
  sky: { main: '#38bdf8', deep: '#0369a1', light: '#e0f2fe' },
  violet: { main: '#a78bfa', deep: '#6d28d9', light: '#ede9fe' },
  slate: { main: '#94a3b8', deep: '#334155', light: '#e2e8f0' },
  red: { main: '#f87171', deep: '#b91c1c', light: '#fee2e2' },
  silver: { main: '#cdd3dc', deep: '#8b95a3', light: '#f1f5f9' },
  bronze: { main: '#c98b5e', deep: '#8a5532', light: '#f3d9c4' },
  white: '#ffffff',
} as const;

/** Icon sizes by role. */
export const ICON_SIZE = { inline: 14, small: 16, row: 20, control: 22, tile: 24, feature: 28, nav: 30 } as const;

/** Image aspect ratios used across the app. */
export const ASPECT = { hero: 16 / 9, game: 16 / 9, tournament: 16 / 9 } as const;

/**
 * Layout scale shared by every screen (dp). Page padding is `md`. Nothing on a
 * screen uses a spacing value outside this list.
 */
export const SPACE = { xxs: 4, xs: 8, sm: 12, md: 16, lg: 20, xl: 24, xxl: 32 } as const;

/** Corner radii: labels/compact controls, buttons/inputs, cards, hero cards and sheets. */
export const RADIUS = { sm: 8, md: 12, lg: 16, xl: 20, pill: 999 } as const;

/** Component dimensions that must match wherever the component appears. */
export const SIZE = {
  touch: 44,
  button: 48,
  buttonSm: 40,
  /** Wallet tile and bell button in the Home header. */
  headerControl: 42,
  navIcon: 22,
  navBar: 64,
  avatarHeader: 46,
  avatarRow: 42,
  avatarPodium: 56,
  avatarPodiumFirst: 70,
  avatarProfile: 88,
  coinPill: 20,
} as const;

/** Content never stretches wider than this on tablets and large phones (dp); it centres instead. */
export const MAX_CONTENT_WIDTH = 560;

/** Type scale (sp). Screens pick a role; they do not invent sizes. */
export const TYPE = {
  hero: { fontSize: 24, lineHeight: 30, fontWeight: '700' },
  screenTitle: { fontSize: 22, lineHeight: 28, fontWeight: '700' },
  section: { fontSize: 17, lineHeight: 22, fontWeight: '600' },
  cardTitle: { fontSize: 15, lineHeight: 20, fontWeight: '600' },
  body: { fontSize: 14, lineHeight: 20, fontWeight: '400' },
  bodyStrong: { fontSize: 14, lineHeight: 20, fontWeight: '600' },
  meta: { fontSize: 12, lineHeight: 16, fontWeight: '400' },
  label: { fontSize: 11, lineHeight: 14, fontWeight: '600' },
  money: { fontSize: 22, lineHeight: 28, fontWeight: '700' },
} as const;

export const COLOR_THEMES: Record<ColorThemeKey, ColorTheme> = {
  'purple-glass': {
    label: 'Purple Glassmorphism',
    bg: '#0d0a12',
    sidebar: '#120d19',
    surface: '#19131f',
    surface2: '#21192b',
    surface3: '#291f35',
    border: '#382a46',
    borderStrong: '#4d3a61',
    fg: '#f6f1fb',
    fg2: '#c2b5d0',
    fg3: '#9788a6',
    brand: '#8b35f6',
    brandHover: '#a855f7',
    brandPressed: '#7025d8',
    brandSoft: '#241536',
    brandText: '#b987fb',
    onBrand: '#ffffff',
    ring: '#a855f7',
  },
  'midnight-emerald': {
    label: 'Midnight Emerald',
    bg: '#090f0d',
    sidebar: '#0d1512',
    surface: '#121c18',
    surface2: '#19251f',
    surface3: '#202e27',
    border: '#2b3e34',
    borderStrong: '#3a5446',
    fg: '#f0faf5',
    fg2: '#b5cfc2',
    fg3: '#84a394',
    brand: '#10b981',
    brandHover: '#34d399',
    brandPressed: '#059669',
    brandSoft: '#0f2a21',
    brandText: '#34d399',
    onBrand: '#04140e',
    ring: '#34d399',
  },
  'crimson-arena': {
    label: 'Crimson Arena',
    bg: '#120909',
    sidebar: '#190d0d',
    surface: '#221414',
    surface2: '#2c1a1a',
    surface3: '#372121',
    border: '#4a2b2b',
    borderStrong: '#613838',
    fg: '#fdf1f1',
    fg2: '#d8b8b8',
    fg3: '#ab8b8b',
    brand: '#dc2626',
    brandHover: '#ef4444',
    brandPressed: '#b91c1c',
    brandSoft: '#3a1414',
    brandText: '#f87171',
    onBrand: '#ffffff',
    ring: '#ef4444',
  },
  'cyber-blue': {
    label: 'Cyber Blue',
    bg: '#08111a',
    sidebar: '#0c1824',
    surface: '#111f2d',
    surface2: '#182939',
    surface3: '#1f3346',
    border: '#2a4157',
    borderStrong: '#38566f',
    fg: '#eef6fc',
    fg2: '#b1c8da',
    fg3: '#8da5b8',
    brand: '#0ea5e9',
    brandHover: '#38bdf8',
    brandPressed: '#0284c7',
    brandSoft: '#0f2638',
    brandText: '#38bdf8',
    onBrand: '#04121c',
    ring: '#38bdf8',
  },
  'sunset-gold': {
    label: 'Sunset Gold',
    bg: '#120d07',
    sidebar: '#19120a',
    surface: '#221a0f',
    surface2: '#2c2214',
    surface3: '#372b1a',
    border: '#4a3a22',
    borderStrong: '#614c2d',
    fg: '#fdf6ec',
    fg2: '#d9c6a5',
    fg3: '#ae9c80',
    brand: '#f59e0b',
    brandHover: '#fbbf24',
    brandPressed: '#d97706',
    brandSoft: '#33220a',
    brandText: '#fbbf24',
    onBrand: '#1a1004',
    ring: '#fbbf24',
  },
  'royal-indigo': {
    label: 'Royal Indigo',
    bg: '#0a0a18',
    sidebar: '#0f0f20',
    surface: '#161629',
    surface2: '#1d1d35',
    surface3: '#262643',
    border: '#34345a',
    borderStrong: '#46467a',
    fg: '#f1f1fd',
    fg2: '#babad8',
    fg3: '#9292b4',
    brand: '#5558e8',
    brandHover: '#7c7ff5',
    brandPressed: '#4338ca',
    brandSoft: '#1b1b3d',
    brandText: '#a5a8fb',
    onBrand: '#ffffff',
    ring: '#7c7ff5',
  },
  'monochrome-steel': {
    label: 'Monochrome Steel',
    bg: '#0e0f11',
    sidebar: '#131518',
    surface: '#1a1d21',
    surface2: '#22262b',
    surface3: '#2b3036',
    border: '#373d45',
    borderStrong: '#4a525c',
    fg: '#f3f5f7',
    fg2: '#bdc4cc',
    fg3: '#99a1ab',
    brand: '#94a3b8',
    brandHover: '#cbd5e1',
    brandPressed: '#7a8799',
    brandSoft: '#20262e',
    brandText: '#cbd5e1',
    onBrand: '#0b0f14',
    ring: '#cbd5e1',
  },
};

export const DEFAULT_COLOR_THEME: ColorThemeKey = 'purple-glass';

/**
 * RN font family names differ per platform (there's no cross-platform
 * "Georgia" etc.), so each font theme is a Platform.select() rather than
 * a single string. No custom font files are loaded — these are all
 * built-in system fonts, so a theme switch takes effect immediately with
 * nothing to pre-load.
 */
export const FONT_THEMES: Record<FontThemeKey, { label: string; fontFamily?: string }> = {
  'default-sans': { label: 'Default Sans', fontFamily: Platform.select({ ios: 'System', android: 'sans-serif', default: undefined }) },
  'modern-grotesk': { label: 'Modern Grotesk', fontFamily: Platform.select({ ios: 'Helvetica Neue', android: 'sans-serif-medium', default: undefined }) },
  'rounded-friendly': { label: 'Rounded Friendly', fontFamily: Platform.select({ ios: 'System', android: 'sans-serif-light', default: undefined }) },
  'classic-serif': { label: 'Classic Serif', fontFamily: Platform.select({ ios: 'Georgia', android: 'serif', default: undefined }) },
  'technical-mono': { label: 'Technical Mono', fontFamily: Platform.select({ ios: 'Menlo', android: 'monospace', default: undefined }) },
  'condensed-impact': { label: 'Condensed Impact', fontFamily: Platform.select({ ios: 'System', android: 'sans-serif-condensed', default: undefined }) },
  'elegant-display': { label: 'Elegant Display', fontFamily: Platform.select({ ios: 'Didot', android: 'serif', default: undefined }) },
};

export const DEFAULT_FONT_THEME: FontThemeKey = 'default-sans';
