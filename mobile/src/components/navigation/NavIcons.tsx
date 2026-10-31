import Svg, { Circle, Path, Rect } from 'react-native-svg';

/**
 * The bottom nav's own icon set — WhatsApp-style outline/filled pairs, pure white (or the
 * one `color` passed in), no category colours, no gradients. Each name has two completely
 * separate shapes (not one shape with a fill toggle) so the outline can stay truly hollow
 * and the filled version can be a real solid silhouette, per the nav redesign spec.
 *
 * The Spin wheel is custom-drawn here (it isn't part of the shared theme/icons.tsx set):
 * a ring with three slots, rotated 20°/140°/260° around the centre so the trio reads as a
 * slight upper-right-tilted pinwheel rather than three slots sitting dead level.
 */
export type NavIconName = 'leaderboard' | 'spin' | 'home' | 'profile';

type Props = {
  name: NavIconName;
  /** false = hollow outline (the unselected state); true = solid silhouette (selected). */
  filled: boolean;
  size?: number;
  color?: string;
};

const STROKE = 1.9;
/** The three spin-wheel slot angles — 120° apart, biased so the set tilts to the upper right. */
const SLOT_ANGLES = [20, 140, 260] as const;
/** Same slot geometry feeds both the stroked outline rects and the filled cut-out path, so the two states line up exactly. */
const SLOT = { halfWidth: 1.15, innerRadius: 3.6, outerRadius: 8.6 };
// The filled wheel's three holes: the same SLOT rectangle as the outline, rotated by each of
// SLOT_ANGLES around the centre (12,12) and written out as absolute corner coordinates — a
// single <Path> can't rotate one of its own subpaths, so the rotation is baked in here instead.
const SPIN_HOLES =
  'M13.86,3.53 L16.02,4.31 L14.31,9.01 L12.15,8.22 Z' +
  'M18.41,17.85 L16.65,19.33 L13.43,15.50 L15.19,14.02 Z' +
  'M3.73,14.63 L3.33,12.36 L8.25,11.49 L8.65,13.76 Z';

export function NavIcon({ name, filled, size = 24, color = '#ffffff' }: Props) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {filled ? renderFilled(name, color) : renderOutline(name, color)}
    </Svg>
  );
}

function renderOutline(name: NavIconName, color: string) {
  const common = { stroke: color, strokeWidth: STROKE, fill: 'none', strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };

  switch (name) {
    case 'leaderboard':
      // A trophy cup: bowl, two side handles, stem and base — hollow throughout.
      return (
        <>
          <Path d="M8 4h8v5a4 4 0 0 1-8 0V4Z" {...common} />
          <Path d="M8 6H5a2 2 0 0 0 2 4h1" {...common} />
          <Path d="M16 6h3a2 2 0 0 1-2 4h-1" {...common} />
          <Path d="M12 13v4" {...common} />
          <Path d="M8.5 20h7" {...common} />
          <Rect x={10} y={16.6} width={4} height={3.2} rx={0.7} {...common} />
        </>
      );
    case 'spin':
      return (
        <>
          <Circle cx={12} cy={12} r={9} {...common} strokeWidth={1.7} />
          {SLOT_ANGLES.map((angle) => (
            <Rect
              key={angle}
              x={12 - SLOT.halfWidth}
              y={12 - SLOT.outerRadius}
              width={SLOT.halfWidth * 2}
              height={SLOT.outerRadius - SLOT.innerRadius}
              rx={SLOT.halfWidth * 0.8}
              {...common}
              strokeWidth={1.5}
              transform={`rotate(${angle} 12 12)`}
            />
          ))}
        </>
      );
    case 'home':
      return (
        <>
          <Path d="M4 11l8-7 8 7" {...common} />
          <Path d="M6 10v10h12V10" {...common} />
        </>
      );
    case 'profile':
      return (
        <>
          <Circle cx={12} cy={8} r={4} {...common} />
          <Path d="M4 20c0-4 3.5-7 8-7s8 3 8 7" {...common} />
        </>
      );
  }
}

/** The solid, single-colour silhouette for the selected state — no accent, no category tint. */
function renderFilled(name: NavIconName, color: string) {
  switch (name) {
    case 'leaderboard':
      // Same cup, now solid: the two handles stay thin strokes (the only way to keep them
      // readable as "handles" rather than two blobs), everything else is a filled shape.
      return (
        <>
          <Path d="M7.2 5.2H4.6a1 1 0 0 0-1 1.1 4.6 4.6 0 0 0 4.3 4.1" fill="none" stroke={color} strokeWidth={1.7} strokeLinecap="round" />
          <Path d="M16.8 5.2h2.6a1 1 0 0 1 1 1.1 4.6 4.6 0 0 1-4.3 4.1" fill="none" stroke={color} strokeWidth={1.7} strokeLinecap="round" />
          <Path d="M6.5 2.8h11v5.4a5.5 5.5 0 0 1-11 0Z" fill={color} />
          <Rect x={10.8} y={13.1} width={2.4} height={3.4} fill={color} />
          <Rect x={7} y={16.5} width={10} height={4.2} rx={1.2} fill={color} />
        </>
      );
    case 'spin':
      // One solid disc with the three slots cut out as true negative space (evenodd holes),
      // so the ring reads exactly like the outline's slots, just filled.
      return <Path d={`M21,12 A9,9 0 1 0 3,12 A9,9 0 1 0 21,12 Z ${SPIN_HOLES}`} fill={color} fillRule="evenodd" />;
    case 'home':
      return <Path d="M12 2.8 2.7 10.5a1 1 0 0 0 .64 1.77H5V20a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 20v-7.73h1.66a1 1 0 0 0 .64-1.77Z" fill={color} />;
    case 'profile':
      return (
        <>
          <Circle cx={12} cy={7.8} r={4.3} fill={color} />
          <Path d="M3.5 20.1c0-4.4 3.8-7.3 8.5-7.3s8.5 2.9 8.5 7.3a.9.9 0 0 1-.9.9H4.4a.9.9 0 0 1-.9-.9Z" fill={color} />
        </>
      );
  }
}
