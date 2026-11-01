import { useEffect, useRef } from 'react';
import { Animated, Image, View } from 'react-native';

import { EASE } from '../theme/motion';
import type { SpinSegment } from './types';

const FRAME_IMAGE = require('../../assets/spin/spin-frame.png');
const WHEEL_IMAGE = require('../../assets/spin/spin-wheel.png');

// The frame's own cropped size (mobile/assets/spin/spin-frame.png) and the wheel's inner
// opening as a share of the frame's width — both measured directly from the supplied
// artwork (see the extraction notes in qa-report), not guessed. Every display size below
// scales from these two numbers so the wheel always sits exactly inside the frame's ring,
// at any screen width, with neither image stretched off its own aspect ratio.
export const FRAME_ASPECT = 833 / 900;
const WHEEL_ASPECT = 770 / 766;
const INNER_RATIO = 0.6843;

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
  const wheelWidth = size * INNER_RATIO;
  const wheelHeight = wheelWidth / WHEEL_ASPECT;

  return (
    <View style={{ width: size, height: frameHeight }}>
      <Animated.View
        style={{
          position: 'absolute',
          left: (size - wheelWidth) / 2,
          top: (frameHeight - wheelHeight) / 2,
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
