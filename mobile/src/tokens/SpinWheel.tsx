import { useEffect, useRef } from 'react';
import { Animated, Image, View } from 'react-native';

import { EASE } from '../theme/motion';
import type { SpinSegment } from './types';

const FRAME_IMAGE = require('../../assets/spin/spin-frame.png');
const WHEEL_IMAGE = require('../../assets/spin/spin-wheel.png');

// Every measurement below comes from inspecting the actual PNGs' alpha channels (a flood
// fill of each image's enclosed content/hole), not the bare canvas dimensions — see the
// derivation in qa-report/spin-token-rewards/ui-fix.
//
// Frame (mobile/assets/spin/spin-frame.png), 833x900: its circular opening is a fully
// enclosed transparent hole measurably off-centre from the image's own midpoint — centre
// (409, 437), i.e. 7.5px left / 13px up of (416.5, 450) — sized 572 wide x 550 tall (not a
// perfect circle; the ornate artwork's hole is very slightly wider than it is tall).
export const FRAME_ASPECT = 833 / 900;
const FRAME_WIDTH_PX = 833;
const OPENING_CENTER_X = 409 / FRAME_WIDTH_PX;
const OPENING_CENTER_Y = 437 / 900;
const OPENING_WIDTH_PX = 572;
const OPENING_HEIGHT_PX = 550;

// Wheel (mobile/assets/spin/spin-wheel.png), 770x766 canvas: the visible coloured disc only
// occupies a 743x741 content box inside it (a ~12-14px transparent margin on every side,
// symmetric to within a pixel) — sizing by the raw canvas, as the previous pass did, was
// quietly rendering the disc itself about 3.5% smaller than intended.
const WHEEL_ASPECT = 770 / 766;
const WHEEL_CANVAS_WIDTH_PX = 770;
const WHEEL_CONTENT_WIDTH_PX = 743;

/**
 * The wheel's own disc is painted with its own purple decorative rim right at its edge
 * (confirmed by sampling the PNG: alpha ramps 0 -> 173 -> opaque purple over its outermost
 * ~25px) — it is drawn to meet the frame's inner gold ring directly, not to float inside it
 * with a background gap. So the disc is sized to the opening's WIDTH (its wider axis): that
 * makes it land very slightly larger than the opening's own height (OPENING_HEIGHT_PX) too,
 * but that harmless few-px overflow is masked by the frame's own opaque ring (frame renders
 * on top), while sizing to the narrower axis instead leaves the dark screen background
 * visible as a ring between the disc's rim and the frame — confirmed by rendering both and
 * comparing side by side.
 */
const OPENING_FIT_DIAMETER_PX = Math.max(OPENING_WIDTH_PX, OPENING_HEIGHT_PX);

const SPIN_DURATION_MS = 3400;
const MIN_EXTRA_SPINS = 4;
const MAX_EXTRA_SPINS = 6;

type Props = {
  /** The wheel's fixed 8 segments, as the server sends them (SpinStatus.segments) — never
   *  hardcoded here, so a future server-side change to the layout is never silently ignored. */
  segments: SpinSegment[];
  /** The server-confirmed winning number to land on next. Only read when `requestId` changes. */
  targetNumber: number | null;
  /** Bump this to start a new spin toward `targetNumber`. Never spin twice for one bump. */
  requestId: number;
  /** Fires once the wheel has physically stopped on the target — the right moment for the
   *  winning popup, the token-balance increment and the win sound, never earlier. */
  onSpinComplete?: (landedNumber: number) => void;
  /** The frame's own display width (dp); the wheel is sized from it. */
  size: number;
};

/**
 * The two supplied images as two independent layers, exactly as the spec requires: the
 * ornate frame (with its own built-in pointer) stays perfectly still; only the numbered
 * wheel underneath it rotates. The wheel only ever spins forward (its rotation is a
 * cumulative degree value that keeps climbing across spins, never reset or animated
 * backward), decelerating into the exact segment the server already chose — the frontend
 * picks which of that number's 1-3 physical segments to land on (purely cosmetic) but never
 * changes the number itself.
 */
export function SpinWheel({ segments, targetNumber, requestId, onSpinComplete, size }: Props) {
  const rotation = useRef(new Animated.Value(0)).current;
  const currentDeg = useRef(0);
  const lastHandledRequest = useRef(0);

  useEffect(() => {
    if (targetNumber === null || requestId === lastHandledRequest.current) return;
    lastHandledRequest.current = requestId;

    const candidates = segments.filter((segment) => segment.number === targetNumber);
    const chosen = candidates[Math.floor(Math.random() * candidates.length)] ?? segments[0];
    if (!chosen) return;

    // Rotating the wheel clockwise by (360 - angle) mod 360 brings a segment currently at
    // `angle` (clockwise from the fixed top pointer) up to that pointer.
    const requiredMod = (360 - chosen.angle + 360) % 360;
    const currentMod = ((currentDeg.current % 360) + 360) % 360;
    const forwardDelta = (requiredMod - currentMod + 360) % 360;
    const extraSpins = MIN_EXTRA_SPINS + Math.floor(Math.random() * (MAX_EXTRA_SPINS - MIN_EXTRA_SPINS + 1));
    const finalDeg = currentDeg.current + forwardDelta + extraSpins * 360;

    Animated.timing(rotation, { toValue: finalDeg, duration: SPIN_DURATION_MS, easing: EASE.out, useNativeDriver: true }).start(({ finished }) => {
      if (finished) {
        currentDeg.current = finalDeg;
        onSpinComplete?.(targetNumber);
      }
    });
  }, [requestId, targetNumber, segments, onSpinComplete, rotation]);

  const frameHeight = size / FRAME_ASPECT;
  // All of this is done in the frame's own 833-wide pixel space first (where every measured
  // constant above already lives), then scaled once by `k` — exact for both axes, since the
  // frame is never letterboxed against its own aspect ratio.
  const k = size / FRAME_WIDTH_PX;
  const wheelCanvasWidthPx = OPENING_FIT_DIAMETER_PX * (WHEEL_CANVAS_WIDTH_PX / WHEEL_CONTENT_WIDTH_PX);
  const wheelWidth = wheelCanvasWidthPx * k;
  const wheelHeight = wheelWidth / WHEEL_ASPECT;
  const openingCenterX = size * OPENING_CENTER_X;
  const openingCenterY = frameHeight * OPENING_CENTER_Y;

  return (
    <View style={{ width: size, height: frameHeight }}>
      <Animated.View
        style={{
          position: 'absolute',
          left: openingCenterX - wheelWidth / 2,
          top: openingCenterY - wheelHeight / 2,
          width: wheelWidth,
          height: wheelHeight,
          transform: [{ rotate: rotation.interpolate({ inputRange: [0, 360], outputRange: ['0deg', '360deg'] }) }],
        }}
      >
        <Image source={WHEEL_IMAGE} style={{ width: wheelWidth, height: wheelHeight }} resizeMode="contain" accessibilityElementsHidden />
      </Animated.View>
      <Image
        source={FRAME_IMAGE}
        style={{ position: 'absolute', left: 0, top: 0, width: size, height: frameHeight }}
        resizeMode="contain"
        accessibilityElementsHidden
      />
    </View>
  );
}
