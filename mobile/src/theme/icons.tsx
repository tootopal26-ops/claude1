import Svg, { Circle, G, Line, Path, Polyline, Rect } from 'react-native-svg';

import { useTheme } from './ThemeContext';
import { ICON_COLORS } from './tokens';

/**
 * The shared 24x24 icon set, in two styles:
 *
 * - **Filled** (the default): finished, category-coloured artwork — gold for trophies,
 *   bells and locks, green for live/positive actions, blue for dates and documents, the
 *   theme's purple for home, wallet, redeem and tournaments. Used wherever an icon stands
 *   on its own (menus, cards, headers, empty states).
 * - **Glyph**: the 2px rounded-stroke outline, mirroring backend/app/View/Components/Icon.php.
 *   Used when the caller passes `color` (an icon that must take one colour, such as white on
 *   a purple button) and for pure UI glyphs (chevrons, close, check, eye, refresh).
 *
 * Only shapes react-native-svg renders on Android are used (paths, rects, circles, groups,
 * opacity) — no filters, masks or blur.
 */
export type IconName =
  | 'tournaments'
  | 'wallet'
  | 'withdrawal'
  | 'deposit'
  | 'leaderboard'
  | 'salary'
  | 'storage'
  | 'user'
  | 'presets'
  | 'audit'
  | 'notifications'
  | 'logout'
  | 'check'
  | 'close'
  | 'chevron-right'
  | 'plus'
  | 'trash'
  | 'upload'
  | 'download'
  | 'home'
  | 'image'
  | 'alert'
  | 'redeem'
  | 'lock'
  | 'unlock'
  | 'trophy'
  | 'play'
  | 'calendar'
  | 'clock'
  | 'check-circle'
  | 'copy'
  | 'eye'
  | 'eye-off'
  | 'chevron-left'
  | 'users'
  | 'target'
  | 'file-text'
  | 'settings'
  | 'refresh'
  | 'wifi-off'
  | 'info';

type IconProps = {
  name: IconName;
  size?: number;
  /**
   * Draws the single-colour outline glyph in this colour (react-native-svg has no
   * currentColor, so pass a theme token). Leave it out to get the filled, coloured icon.
   */
  color?: string;
  /** Second tone for glyphs that have one (the reward star on `redeem`). Defaults to the brand color. */
  accent?: string;
};

/**
 * Icons are decorative: they sit beside a text label (or inside a control that already has
 * an accessibility label), so they are hidden from screen readers.
 */
export function Icon({ name, size = 24, color, accent }: IconProps) {
  const { colors } = useTheme();
  const filled = color === undefined ? renderFilled(name, { main: colors.brand, deep: colors.brandPressed, light: colors.brandText }) : null;
  const strokeColor = color ?? GLYPH_TINT[name] ?? colors.fg;
  const common = { fill: 'none', stroke: strokeColor, strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };

  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {filled ?? renderIcon(name, common, accent ?? colors.brand)}
    </Svg>
  );
}

/** Outline-only icons that still carry a category colour when no colour is passed. */
const GLYPH_TINT: Partial<Record<IconName, string>> = {
  'wifi-off': ICON_COLORS.red.main,
  alert: ICON_COLORS.gold.main,
};

type Tone = { main: string; deep: string; light: string };

const W = ICON_COLORS.white;

/** The filled, coloured artwork; null when an icon only exists as a glyph. */
function renderFilled(name: IconName, brand: Tone) {
  const gold = ICON_COLORS.gold;
  const green = ICON_COLORS.green;
  const blue = ICON_COLORS.blue;
  const sky = ICON_COLORS.sky;
  const violet = ICON_COLORS.violet;
  const slate = ICON_COLORS.slate;
  const red = ICON_COLORS.red;

  switch (name) {
    case 'home':
      return (
        <G>
          <Path d="M12 2.8 2.7 10.5a1 1 0 0 0 .64 1.77H5V20a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 20v-7.73h1.66a1 1 0 0 0 .64-1.77Z" fill={brand.main} />
          <Path d="M12 2.8 2.7 10.5a1 1 0 0 0 .64 1.77H5L12 6.5l7 5.77h1.66a1 1 0 0 0 .64-1.77Z" fill={brand.deep} />
          <Rect x={9.75} y={14.2} width={4.5} height={7.3} rx={1.4} fill={W} opacity={0.92} />
        </G>
      );
    case 'trophy':
      return (
        <G>
          <Path d="M7.2 5.5H4.6a1 1 0 0 0-1 1.1 4.6 4.6 0 0 0 4.3 4.1" fill="none" stroke={gold.deep} strokeWidth={1.9} strokeLinecap="round" />
          <Path d="M16.8 5.5h2.6a1 1 0 0 1 1 1.1 4.6 4.6 0 0 1-4.3 4.1" fill="none" stroke={gold.deep} strokeWidth={1.9} strokeLinecap="round" />
          <Path d="M6.5 3h11v5.6a5.5 5.5 0 0 1-11 0Z" fill={gold.main} />
          <Path d="M8.9 4.7v3.7a3.2 3.2 0 0 0 1.3 2.6" fill="none" stroke={gold.light} strokeWidth={1.3} strokeLinecap="round" />
          <Rect x={10.8} y={13.6} width={2.4} height={3.6} fill={gold.deep} />
          <Rect x={7} y={17} width={10} height={4.3} rx={1.3} fill={gold.deep} />
          <Rect x={9.5} y={18.4} width={5} height={1.4} rx={0.7} fill={gold.main} />
        </G>
      );
    case 'user':
      return (
        <G>
          <Circle cx={12} cy={7.8} r={4.3} fill={violet.main} />
          <Path d="M3.5 20.1c0-4.4 3.8-7.3 8.5-7.3s8.5 2.9 8.5 7.3a.9.9 0 0 1-.9.9H4.4a.9.9 0 0 1-.9-.9Z" fill={violet.deep} />
        </G>
      );
    case 'users':
      return (
        <G>
          <Circle cx={16} cy={7.6} r={3.2} fill={violet.deep} />
          <Path d="M13.2 13.2a6 6 0 0 1 8.3 5.6.8.8 0 0 1-.8.8h-4.6" fill={violet.deep} />
          <Circle cx={9} cy={8.4} r={3.8} fill={violet.main} />
          <Path d="M2 19.6c0-3.7 3.1-6.2 7-6.2s7 2.5 7 6.2a.8.8 0 0 1-.8.8H2.8a.8.8 0 0 1-.8-.8Z" fill={violet.main} />
        </G>
      );
    case 'play':
      return (
        <G>
          <Circle cx={12} cy={12} r={10} fill={green.main} />
          <Path d="M10 7.9v8.2l6.6-4.1Z" fill={W} />
        </G>
      );
    case 'check-circle':
      return (
        <G>
          <Circle cx={12} cy={12} r={10} fill={green.main} />
          <Path d="M7.6 12.3l3 3 5.8-6.2" fill="none" stroke={W} strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" />
        </G>
      );
    case 'calendar':
      return (
        <G>
          <Rect x={3} y={4.5} width={18} height={16.5} rx={3} fill={blue.light} />
          <Path d="M3 7.5a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v2.8H3Z" fill={blue.main} />
          <Rect x={7} y={2.4} width={2.2} height={4.6} rx={1.1} fill={blue.deep} />
          <Rect x={14.8} y={2.4} width={2.2} height={4.6} rx={1.1} fill={blue.deep} />
          <Rect x={6.2} y={12.6} width={3} height={2.6} rx={0.8} fill={blue.main} />
          <Rect x={10.5} y={12.6} width={3} height={2.6} rx={0.8} fill={blue.main} opacity={0.45} />
          <Rect x={14.8} y={12.6} width={3} height={2.6} rx={0.8} fill={blue.main} opacity={0.45} />
          <Rect x={6.2} y={16.5} width={3} height={2.6} rx={0.8} fill={blue.main} opacity={0.45} />
          <Rect x={10.5} y={16.5} width={3} height={2.6} rx={0.8} fill={blue.main} opacity={0.45} />
        </G>
      );
    case 'clock':
      return (
        <G>
          <Circle cx={12} cy={12} r={10} fill={gold.main} />
          <Circle cx={12} cy={12} r={7.4} fill={gold.light} />
          <Path d="M12 7.6V12l3 2.1" fill="none" stroke={gold.deep} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
        </G>
      );
    case 'notifications':
      return (
        <G>
          <Path d="M12 2.4a1.5 1.5 0 0 1 1.5 1.5v.7a6.4 6.4 0 0 1 4.9 6.2v3.7l1.7 2.4a1 1 0 0 1-.82 1.6H4.72a1 1 0 0 1-.82-1.6l1.7-2.4v-3.7a6.4 6.4 0 0 1 4.9-6.2v-.7A1.5 1.5 0 0 1 12 2.4Z" fill={gold.main} />
          <Path d="M9.4 19.5h5.2a2.6 2.6 0 0 1-5.2 0Z" fill={gold.deep} />
          <Path d="M8.1 10.6a4 4 0 0 1 2.3-3.5" fill="none" stroke={gold.light} strokeWidth={1.4} strokeLinecap="round" />
        </G>
      );
    case 'wallet':
      return (
        <G>
          <Path d="M5 4.2h11.6a1.6 1.6 0 0 1 1.6 1.6v1.4H5Z" fill={brand.deep} />
          <Rect x={2.5} y={6.4} width={19} height={14.2} rx={3} fill={brand.main} />
          <Rect x={14.2} y={10.8} width={7.3} height={5.4} rx={2.2} fill={brand.deep} />
          <Circle cx={17.4} cy={13.5} r={1.3} fill={gold.main} />
        </G>
      );
    case 'deposit':
      return (
        <G>
          <Circle cx={12} cy={12} r={10} fill={green.main} />
          <Path d="M12 16.9V7.6M8.1 11.4 12 7.5l3.9 3.9" fill="none" stroke={W} strokeWidth={2.3} strokeLinecap="round" strokeLinejoin="round" />
        </G>
      );
    case 'withdrawal':
      return (
        <G>
          <Circle cx={12} cy={12} r={10} fill={sky.main} />
          <Path d="M12 7.1v9.3M8.1 12.6l3.9 3.9 3.9-3.9" fill="none" stroke={W} strokeWidth={2.3} strokeLinecap="round" strokeLinejoin="round" />
        </G>
      );
    case 'redeem':
      return (
        <G>
          <Path d="M2.5 7.6A1.6 1.6 0 0 1 4.1 6h15.8a1.6 1.6 0 0 1 1.6 1.6v2.6a1.9 1.9 0 0 0 0 3.6v2.6a1.6 1.6 0 0 1-1.6 1.6H4.1a1.6 1.6 0 0 1-1.6-1.6v-2.6a1.9 1.9 0 0 0 0-3.6Z" fill={brand.main} />
          <Path d="M15.4 7.5v9" fill="none" stroke={brand.light} strokeWidth={1.3} strokeDasharray="1.2 1.6" strokeLinecap="round" />
          <Rect x={5.4} y={10.2} width={7.4} height={3.6} rx={1.2} fill={brand.deep} />
          <Path d="M18.4 9.6l.7 1.6 1.6.7-1.6.7-.7 1.6-.7-1.6-1.6-.7 1.6-.7Z" fill={gold.main} />
        </G>
      );
    case 'settings':
      return (
        <G>
          <Path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z" fill={slate.main} />
          <Circle cx={12} cy={12} r={3.4} fill={slate.deep} />
        </G>
      );
    case 'lock':
    case 'unlock': {
      const body = name === 'lock' ? gold : green;
      return (
        <G>
          <Path d={name === 'lock' ? 'M8 10.6V7.6a4 4 0 0 1 8 0v3' : 'M8 10.6V7.6a4 4 0 0 1 7.6-1.7'} fill="none" stroke={slate.main} strokeWidth={2.4} strokeLinecap="round" />
          <Rect x={4.4} y={10.2} width={15.2} height={11} rx={3} fill={body.main} />
          <Circle cx={12} cy={14.7} r={1.6} fill={body.deep} />
          <Rect x={11.2} y={15.2} width={1.6} height={3} rx={0.8} fill={body.deep} />
        </G>
      );
    }
    case 'copy':
      return (
        <G>
          <Rect x={3.8} y={3.4} width={11.6} height={13.4} rx={2.4} fill={violet.deep} />
          <Rect x={8.6} y={7.8} width={11.6} height={13.4} rx={2.4} fill={violet.main} />
          <Rect x={11.2} y={11.6} width={6.4} height={1.6} rx={0.8} fill={W} opacity={0.85} />
          <Rect x={11.2} y={15} width={4.6} height={1.6} rx={0.8} fill={W} opacity={0.85} />
        </G>
      );
    case 'file-text':
      return (
        <G>
          <Path d="M14.2 2.5H6.6A2.1 2.1 0 0 0 4.5 4.6v14.8a2.1 2.1 0 0 0 2.1 2.1h10.8a2.1 2.1 0 0 0 2.1-2.1V7.8Z" fill={blue.main} />
          <Path d="M14.2 2.5v4.2a1.1 1.1 0 0 0 1.1 1.1h4.2Z" fill={blue.light} />
          <Rect x={7.6} y={11.6} width={8.8} height={1.6} rx={0.8} fill={W} />
          <Rect x={7.6} y={15.4} width={6.4} height={1.6} rx={0.8} fill={W} />
        </G>
      );
    case 'tournaments':
      return (
        <G>
          <Rect x={3.6} y={2.4} width={2.2} height={19.2} rx={1.1} fill={slate.main} />
          <Path d="M5.8 3.8h12.4a.8.8 0 0 1 .63 1.3L16 8.6l2.83 3.5a.8.8 0 0 1-.63 1.3H5.8Z" fill={brand.main} />
          <Path d="M5.8 3.8h12.4a.8.8 0 0 1 .63 1.3L16 8.6H5.8Z" fill={brand.light} opacity={0.35} />
        </G>
      );
    case 'leaderboard':
      return (
        <G>
          <Rect x={2.8} y={13} width={5.4} height={8.2} rx={1.3} fill={ICON_COLORS.bronze.main} />
          <Rect x={9.3} y={4} width={5.4} height={17.2} rx={1.3} fill={gold.main} />
          <Rect x={15.8} y={9} width={5.4} height={12.2} rx={1.3} fill={ICON_COLORS.silver.main} />
        </G>
      );
    case 'info':
      return (
        <G>
          <Circle cx={12} cy={12} r={10} fill={blue.main} />
          <Rect x={10.9} y={10.4} width={2.2} height={7} rx={1.1} fill={W} />
          <Circle cx={12} cy={7.6} r={1.35} fill={W} />
        </G>
      );
    case 'logout':
      return (
        <G>
          <Path d="M4 4.6A1.6 1.6 0 0 1 5.6 3H12v18H5.6A1.6 1.6 0 0 1 4 19.4Z" fill={red.main} />
          <Path d="M10.5 12h10M17 8.4l3.6 3.6-3.6 3.6" fill="none" stroke={red.deep} strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" />
        </G>
      );
    case 'image':
      return (
        <G>
          <Rect x={2.5} y={4} width={19} height={16} rx={3} fill={slate.deep} />
          <Circle cx={8.6} cy={9.4} r={1.9} fill={gold.main} />
          <Path d="M2.5 17.4 8.4 12.2l3.4 3 4.4-4.4 5.3 5.3V17a3 3 0 0 1-3 3h-13a3 3 0 0 1-3-2.6Z" fill={slate.main} />
        </G>
      );
    default:
      return null;
  }
}

function renderIcon(name: IconName, common: Record<string, unknown>, accent: string) {
  switch (name) {
    case 'tournaments':
      return (
        <>
          <Path d="M4 3v18" {...common} />
          <Path d="M4 4h12l-3 4 3 4H4" {...common} />
        </>
      );
    case 'wallet':
      return (
        <>
          <Rect x={2} y={6} width={20} height={12} rx={2} {...common} />
          <Path d="M16 14h2" {...common} />
        </>
      );
    case 'withdrawal':
      return (
        <>
          <Path d="M12 3v12" {...common} />
          <Polyline points="7 10 12 15 17 10" {...common} />
          <Path d="M4 20h16" {...common} />
        </>
      );
    case 'deposit':
      return (
        <>
          <Path d="M12 21V9" {...common} />
          <Polyline points="7 14 12 9 17 14" {...common} />
          <Path d="M4 4h16" {...common} />
        </>
      );
    case 'leaderboard':
      return (
        <>
          <Rect x={4} y={14} width={4} height={6} {...common} />
          <Rect x={10} y={9} width={4} height={11} {...common} />
          <Rect x={16} y={4} width={4} height={16} {...common} />
        </>
      );
    case 'salary':
      return (
        <>
          <Rect x={2} y={6} width={20} height={12} rx={2} {...common} />
          <Circle cx={12} cy={12} r={3} {...common} />
        </>
      );
    case 'storage':
      return <Path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" {...common} />;
    case 'user':
      return (
        <>
          <Circle cx={12} cy={8} r={4} {...common} />
          <Path d="M4 20c0-4 3.5-7 8-7s8 3 8 7" {...common} />
        </>
      );
    case 'presets':
      return (
        <>
          <Line x1={4} y1={6} x2={20} y2={6} {...common} />
          <Circle cx={9} cy={6} r={2} {...common} />
          <Line x1={4} y1={12} x2={20} y2={12} {...common} />
          <Circle cx={15} cy={12} r={2} {...common} />
          <Line x1={4} y1={18} x2={20} y2={18} {...common} />
          <Circle cx={7} cy={18} r={2} {...common} />
        </>
      );
    case 'audit':
      return (
        <>
          <Rect x={5} y={4} width={14} height={17} rx={2} {...common} />
          <Rect x={9} y={2} width={6} height={4} rx={1} {...common} />
          <Line x1={8} y1={11} x2={16} y2={11} {...common} />
          <Line x1={8} y1={15} x2={16} y2={15} {...common} />
        </>
      );
    case 'notifications':
      return (
        <>
          <Path d="M6 10a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6Z" {...common} />
          <Path d="M10 20a2 2 0 0 0 4 0" {...common} />
        </>
      );
    case 'logout':
      return (
        <>
          <Path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4" {...common} />
          <Polyline points="15 16 20 12 15 8" {...common} />
          <Line x1={20} y1={12} x2={9} y2={12} {...common} />
        </>
      );
    case 'check':
      return <Polyline points="5 12 10 17 20 7" {...common} />;
    case 'close':
      return (
        <>
          <Line x1={5} y1={5} x2={19} y2={19} {...common} />
          <Line x1={19} y1={5} x2={5} y2={19} {...common} />
        </>
      );
    case 'chevron-right':
      return <Polyline points="9 5 16 12 9 19" {...common} />;
    case 'plus':
      return (
        <>
          <Line x1={12} y1={5} x2={12} y2={19} {...common} />
          <Line x1={5} y1={12} x2={19} y2={12} {...common} />
        </>
      );
    case 'trash':
      return (
        <>
          <Path d="M4 7h16" {...common} />
          <Path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2" {...common} />
          <Path d="M19 7l-1 13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7" {...common} />
          <Line x1={10} y1={11} x2={10} y2={17} {...common} />
          <Line x1={14} y1={11} x2={14} y2={17} {...common} />
        </>
      );
    case 'upload':
      return (
        <>
          <Polyline points="7 9 12 4 17 9" {...common} />
          <Line x1={12} y1={4} x2={12} y2={16} {...common} />
          <Path d="M5 20h14" {...common} />
        </>
      );
    case 'download':
      return (
        <>
          <Polyline points="7 11 12 16 17 11" {...common} />
          <Line x1={12} y1={4} x2={12} y2={16} {...common} />
          <Path d="M5 20h14" {...common} />
        </>
      );
    case 'home':
      return (
        <>
          <Path d="M4 11l8-7 8 7" {...common} />
          <Path d="M6 10v10h12V10" {...common} />
        </>
      );
    case 'image':
      return (
        <>
          <Rect x={3} y={4} width={18} height={16} rx={2} {...common} />
          <Circle cx={9} cy={10} r={1.5} {...common} />
          <Path d="M21 16l-5-5L7 20" {...common} />
        </>
      );
    case 'redeem':
      // A voucher with side notches, a code window holding five code marks, a
      // perforation, and a small reward star in the accent tone.
      return (
        <>
          <Path d="M3 8a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2.3a1.7 1.7 0 0 0 0 3.4V16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2.3a1.7 1.7 0 0 0 0-3.4V8Z" {...common} />
          <Rect x={5.5} y={10} width={8} height={4} rx={1.2} {...common} strokeWidth={1.5} />
          {[7.1, 8.5, 9.9, 11.3, 12.7].map((cx) => (
            <Circle key={cx} cx={cx} cy={12} r={0.55} fill={String(common.stroke)} stroke="none" />
          ))}
          <Line x1={15.6} y1={8.8} x2={15.6} y2={15.2} {...common} strokeWidth={1.4} strokeDasharray="1 2" />
          <Path d="M18.4 9.9l.65 1.5 1.5.65-1.5.65-.65 1.5-.65-1.5-1.5-.65 1.5-.65.65-1.5Z" fill={accent} stroke={accent} strokeWidth={0.6} strokeLinejoin="round" />
        </>
      );
    case 'lock':
    case 'unlock':
      // A rounded body, a smooth shackle (closed when locked, lifted and open when unlocked)
      // and a small keyhole in the accent tone — the same geometry in both states, so only
      // the shackle tells them apart.
      return (
        <>
          <Rect x={4.5} y={10.5} width={15} height={10.5} rx={3} {...common} />
          <Path d={name === 'lock' ? 'M8 10.5V7.5a4 4 0 0 1 8 0v3' : 'M8 10.5V7.5a4 4 0 0 1 7.5-1.6'} {...common} />
          <Circle cx={12} cy={14.8} r={1.35} fill={accent} stroke="none" />
          <Line x1={12} y1={15.6} x2={12} y2={18} {...common} stroke={accent} strokeWidth={1.7} />
        </>
      );
    case 'trophy':
      return (
        <>
          <Path d="M8 4h8v5a4 4 0 0 1-8 0V4Z" {...common} />
          <Path d="M8 6H5a2 2 0 0 0 2 4h1" {...common} />
          <Path d="M16 6h3a2 2 0 0 1-2 4h-1" {...common} />
          <Path d="M12 13v4" {...common} />
          <Path d="M8.5 20h7" {...common} />
          <Path d="M10 17h4v3h-4z" {...common} />
        </>
      );
    case 'play':
      return (
        <>
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path d="M10.2 8.8v6.4l5-3.2-5-3.2Z" {...common} fill={accent} stroke={accent} />
        </>
      );
    case 'calendar':
      return (
        <>
          <Rect x={3.5} y={5} width={17} height={15} rx={2.5} {...common} />
          <Path d="M3.5 10h17" {...common} />
          <Path d="M8 3v4" {...common} />
          <Path d="M16 3v4" {...common} />
          <Circle cx={12} cy={15} r={1.3} fill={accent} stroke="none" />
        </>
      );
    case 'clock':
      return (
        <>
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path d="M12 7.5V12l3 2" {...common} />
        </>
      );
    case 'check-circle':
      return (
        <>
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path d="M8.2 12.3l2.6 2.6 5-5.4" {...common} stroke={accent} />
        </>
      );
    case 'copy':
      return (
        <>
          <Rect x={9} y={9} width={11} height={11} rx={2} {...common} />
          <Path d="M5 15V6a2 2 0 0 1 2-2h9" {...common} />
        </>
      );
    case 'eye':
      return (
        <>
          <Path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" {...common} />
          <Circle cx={12} cy={12} r={3} {...common} />
        </>
      );
    case 'eye-off':
      return (
        <>
          <Path d="M10.6 5.6A10 10 0 0 1 12 5.5C18 5.5 21.5 12 21.5 12a17 17 0 0 1-3 3.8" {...common} />
          <Path d="M6.6 6.6C3.9 8.3 2.5 12 2.5 12S6 18.5 12 18.5c1.8 0 3.3-.5 4.6-1.2" {...common} />
          <Path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" {...common} />
          <Line x1={3} y1={3} x2={21} y2={21} {...common} />
        </>
      );
    case 'chevron-left':
      return <Polyline points="15 18 9 12 15 6" {...common} />;
    case 'users':
      return (
        <>
          <Circle cx={9} cy={8} r={3.5} {...common} />
          <Path d="M2.5 20a6.5 6.5 0 0 1 13 0" {...common} />
          <Path d="M16 4.6a3.5 3.5 0 0 1 0 6.8" {...common} />
          <Path d="M18 14.2a6.5 6.5 0 0 1 3.5 5.8" {...common} />
        </>
      );
    case 'target':
      return (
        <>
          <Circle cx={12} cy={12} r={8.5} {...common} />
          <Circle cx={12} cy={12} r={4.5} {...common} />
          <Circle cx={12} cy={12} r={1.2} fill={accent} stroke="none" />
        </>
      );
    case 'file-text':
      return (
        <>
          <Path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" {...common} />
          <Path d="M14 3v5h5" {...common} />
          <Path d="M9 13h6" {...common} />
          <Path d="M9 17h6" {...common} />
        </>
      );
    case 'settings':
      return (
        <>
          <Circle cx={12} cy={12} r={3} {...common} />
          <Path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z" {...common} />
        </>
      );
    case 'refresh':
      return (
        <>
          <Path d="M20 11a8 8 0 0 0-14.3-4.9L4 8" {...common} />
          <Path d="M4 3.5V8h4.5" {...common} />
          <Path d="M4 13a8 8 0 0 0 14.3 4.9L20 16" {...common} />
          <Path d="M20 20.5V16h-4.5" {...common} />
        </>
      );
    case 'wifi-off':
      return (
        <>
          <Line x1={3} y1={3} x2={21} y2={21} {...common} />
          <Path d="M8.5 16.4a5 5 0 0 1 7 0" {...common} />
          <Path d="M5 12.9a10 10 0 0 1 4.2-2.5" {...common} />
          <Path d="M14.7 10.4A10 10 0 0 1 19 12.9" {...common} />
          <Path d="M2 9.4a15 15 0 0 1 4.3-2.8" {...common} />
          <Path d="M11 5.1A15 15 0 0 1 22 9.4" {...common} />
          <Circle cx={12} cy={19.5} r={0.9} fill={String(common.stroke)} stroke="none" />
        </>
      );
    case 'info':
      return (
        <>
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path d="M12 11v5" {...common} />
          <Circle cx={12} cy={7.8} r={0.9} fill={String(common.stroke)} stroke="none" />
        </>
      );
    case 'alert':
      return (
        <>
          <Path d="M12 3l10 18H2L12 3Z" {...common} />
          <Line x1={12} y1={10} x2={12} y2={14} {...common} />
          <Line x1={12} y1={17.5} x2={12} y2={17.6} {...common} />
        </>
      );
  }
}
