import { useEffect, useState } from 'react';
import { AccessibilityInfo, Easing } from 'react-native';

/**
 * One motion language for the whole app. Durations are targets (ms); every animated
 * component takes its timing from here instead of picking its own.
 */
export const DURATION = {
  instant: 120,
  small: 160,
  card: 200,
  screen: 280,
  modal: 240,
  sheet: 300,
  content: 320,
  hero: 750,
  ambient: 2400,
} as const;

export const EASE = {
  /** Entering elements. */
  out: Easing.out(Easing.cubic),
  /** Exiting elements. */
  in: Easing.in(Easing.cubic),
  /** Moving between settled states. */
  inOut: Easing.inOut(Easing.cubic),
} as const;

/** Restrained spring: settles without visible overshoot. */
export const SPRING = { damping: 20, stiffness: 260, mass: 1, useNativeDriver: true } as const;

/** Scale for pressed controls and cards. */
export const PRESS_SCALE = { button: 0.97, card: 0.98, icon: 0.95 } as const;

// One subscription for the whole app, shared by every animated component.
let reducedMotion = false;
const listeners = new Set<(value: boolean) => void>();
let subscribed = false;

function subscribe(listener: (value: boolean) => void) {
  listeners.add(listener);
  if (!subscribed) {
    subscribed = true;
    const publish = (value: boolean) => {
      reducedMotion = value;
      listeners.forEach((notify) => notify(value));
    };
    AccessibilityInfo.isReduceMotionEnabled().then(publish).catch(() => {});
    AccessibilityInfo.addEventListener('reduceMotionChanged', publish);
  }

  return () => {
    listeners.delete(listener);
  };
}

/**
 * True while the system asks for reduced motion. Decorative motion (pulses, autoplay,
 * entrance movement) is switched off; state changes become simple fades or are immediate.
 */
export function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(reducedMotion);

  useEffect(() => subscribe(setReduced), []);

  return reduced;
}
