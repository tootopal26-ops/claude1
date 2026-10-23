import Svg, { Circle, Line, Path, Polyline, Rect } from 'react-native-svg';

import { useTheme } from './ThemeContext';

/**
 * The shared 24x24 stroke icon set, mirroring
 * backend/app/View/Components/Icon.php by name and shape. Keep the two
 * in sync by hand if either list changes.
 *
 * Every icon draws with fill="none" and stroke={color}, defaulting to
 * the active theme's text color (see ThemeContext) rather than a
 * hardcoded value.
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
  | 'unlock';

type IconProps = {
  name: IconName;
  size?: number;
  /**
   * react-native-svg has no concept of CSS's currentColor, so this has
   * no implicit link to surrounding text. Defaults to the active theme's
   * primary text color; pass a token (e.g. colors.fg2 or colors.onBrand)
   * when the icon sits on a different surface.
   */
  color?: string;
  /** Second tone for icons that have one (the reward star on `redeem`). Defaults to the brand color. */
  accent?: string;
};

/**
 * Icons are decorative: they sit beside a text label (or inside a control that
 * already has an accessibility label), so they are hidden from screen readers
 * rather than announced as unnamed images.
 */
export function Icon({ name, size = 24, color, accent }: IconProps) {
  const { colors } = useTheme();
  const common = { fill: 'none', stroke: color ?? colors.fg, strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };

  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {renderIcon(name, common, accent ?? colors.brand)}
    </Svg>
  );
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
