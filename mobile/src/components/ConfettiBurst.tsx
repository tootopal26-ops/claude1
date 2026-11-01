import { useEffect, useRef, useState } from 'react';
import { Animated, View } from 'react-native';

import { useReducedMotion } from '../theme/motion';

const COLORS = ['#f6c343', '#b987fb', '#34d399', '#f87171', '#38bdf8', '#fbbf24'];
const FALL_DISTANCE = 260;

type Particle = {
  id: number;
  color: string;
  left: number; // percent across the burst area
  size: number;
  round: boolean;
  delay: number;
  duration: number;
  drift: number; // horizontal travel, px
  spins: number;
};

function makeParticles(count: number): Particle[] {
  return Array.from({ length: count }, (_, id) => ({
    id,
    color: COLORS[id % COLORS.length],
    left: 4 + Math.random() * 92,
    size: 6 + Math.random() * 6,
    round: Math.random() > 0.5,
    delay: Math.random() * 260,
    duration: 1100 + Math.random() * 500,
    drift: (Math.random() - 0.5) * 90,
    spins: 1 + Math.random() * 2,
  }));
}

/**
 * A short burst of falling, spinning coloured pieces over a win popup — plain Animated.Views,
 * no inline SVG element (so it never trips scripts/check-no-raw-currency.js's SVG allow-list),
 * fully driven on the native thread. Mounted only while the popup showing it is visible, so
 * every mount is a fresh burst; under reduced motion it renders nothing.
 */
export function ConfettiBurst({ count = 26 }: { count?: number }) {
  const reduced = useReducedMotion();
  const [particles] = useState(() => makeParticles(count));

  if (reduced) return null;

  return (
    <View pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={{ position: 'absolute', top: 0, left: 0, right: 0, height: FALL_DISTANCE, overflow: 'hidden' }}>
      {particles.map((particle) => (
        <ConfettiPiece key={particle.id} particle={particle} />
      ))}
    </View>
  );
}

function ConfettiPiece({ particle }: { particle: Particle }) {
  const progress = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    const animation = Animated.timing(progress, { toValue: 1, duration: particle.duration, delay: particle.delay, useNativeDriver: true });
    animation.start();
    return () => animation.stop();
  }, [progress, particle.duration, particle.delay]);

  const translateY = progress.interpolate({ inputRange: [0, 1], outputRange: [-20, FALL_DISTANCE] });
  const translateX = progress.interpolate({ inputRange: [0, 1], outputRange: [0, particle.drift] });
  const rotate = progress.interpolate({ inputRange: [0, 1], outputRange: ['0deg', `${particle.spins * 360}deg`] });
  const opacity = progress.interpolate({ inputRange: [0, 0.75, 1], outputRange: [1, 1, 0] });

  return (
    <Animated.View
      style={{
        position: 'absolute',
        left: `${particle.left}%`,
        width: particle.size,
        height: particle.size,
        borderRadius: particle.round ? particle.size / 2 : 2,
        backgroundColor: particle.color,
        opacity,
        transform: [{ translateY }, { translateX }, { rotate }],
      }}
    />
  );
}
