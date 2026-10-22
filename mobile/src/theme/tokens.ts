import { Platform } from 'react-native';

/**
 * The 7 color themes and 7 font themes, mirroring
 * backend/resources/css/themes.css by key, name, and palette. Keep the
 * two lists in sync by hand if either side changes.
 *
 * Semantic colors (danger/success/warning) carry meaning independent of
 * the chosen theme, so they live outside ColorTheme and stay fixed
 * across all 7 — see SEMANTIC_COLORS below.
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
  background: string;
  surface: string;
  surfaceBorder: string;
  primary: string;
  primaryHover: string;
  text: string;
  textMuted: string;
  accent: string;
};

export const SEMANTIC_COLORS = {
  danger: '#ef4444',
  dangerBg: 'rgba(127,29,29,0.4)',
  success: '#34d399',
  successBg: 'rgba(6,78,59,0.4)',
  warning: '#fbbf24',
  warningBg: 'rgba(120,53,15,0.4)',
};

export const COLOR_THEMES: Record<ColorThemeKey, ColorTheme> = {
  'purple-glass': {
    label: 'Purple Glassmorphism',
    background: '#140a1f',
    surface: 'rgba(26,16,37,0.8)',
    surfaceBorder: 'rgba(88,28,135,0.4)',
    primary: '#8b5cf6',
    primaryHover: '#7c3aed',
    text: '#ffffff',
    textMuted: '#c4b5fd',
    accent: '#a78bfa',
  },
  'midnight-emerald': {
    label: 'Midnight Emerald',
    background: '#0a1f17',
    surface: 'rgba(16,37,28,0.8)',
    surfaceBorder: 'rgba(6,95,70,0.4)',
    primary: '#10b981',
    primaryHover: '#059669',
    text: '#ffffff',
    textMuted: '#6ee7b7',
    accent: '#34d399',
  },
  'crimson-arena': {
    label: 'Crimson Arena',
    background: '#1a0a0a',
    surface: 'rgba(37,16,16,0.8)',
    surfaceBorder: 'rgba(127,29,29,0.4)',
    primary: '#ef4444',
    primaryHover: '#dc2626',
    text: '#ffffff',
    textMuted: '#fca5a5',
    accent: '#f87171',
  },
  'cyber-blue': {
    label: 'Cyber Blue',
    background: '#0a1420',
    surface: 'rgba(16,28,37,0.8)',
    surfaceBorder: 'rgba(12,74,110,0.4)',
    primary: '#0ea5e9',
    primaryHover: '#0284c7',
    text: '#ffffff',
    textMuted: '#7dd3fc',
    accent: '#38bdf8',
  },
  'sunset-gold': {
    label: 'Sunset Gold',
    background: '#1f1509',
    surface: 'rgba(37,27,16,0.8)',
    surfaceBorder: 'rgba(146,64,14,0.4)',
    primary: '#f59e0b',
    primaryHover: '#d97706',
    text: '#ffffff',
    textMuted: '#fcd34d',
    accent: '#fbbf24',
  },
  'royal-indigo': {
    label: 'Royal Indigo',
    background: '#0d0a1f',
    surface: 'rgba(22,16,37,0.8)',
    surfaceBorder: 'rgba(67,56,202,0.4)',
    primary: '#6366f1',
    primaryHover: '#4f46e5',
    text: '#ffffff',
    textMuted: '#a5b4fc',
    accent: '#818cf8',
  },
  'monochrome-steel': {
    label: 'Monochrome Steel',
    background: '#121212',
    surface: 'rgba(30,30,30,0.8)',
    surfaceBorder: 'rgba(100,116,139,0.4)',
    primary: '#94a3b8',
    primaryHover: '#64748b',
    text: '#ffffff',
    textMuted: '#cbd5e1',
    accent: '#e2e8f0',
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
