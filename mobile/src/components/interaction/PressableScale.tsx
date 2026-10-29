import { useRef, type ReactNode } from 'react';
import { Animated, Pressable, type GestureResponderEvent, type PressableProps, type StyleProp, type ViewStyle } from 'react-native';

import { useSoundOptional } from '../../sound/SoundContext';
import type { HapticKind } from '../../sound/engine';
import type { SoundName } from '../../sound/sounds';
import { DURATION, EASE, PRESS_SCALE, useReducedMotion } from '../../theme/motion';

type Props = Omit<PressableProps, 'style' | 'children'> & {
  children: ReactNode;
  /** The visual box (background, border, padding). It scales on press. */
  style?: StyleProp<ViewStyle>;
  /** Outer layout (flex, width, margins). It never moves. */
  layoutStyle?: StyleProp<ViewStyle>;
  /** Pressed scale; defaults to the card scale. */
  scaleTo?: number;
  /** Extra style while pressed (for example a slightly lighter surface). */
  pressedStyle?: StyleProp<ViewStyle>;
  /** Interaction sound to play on a completed tap (null = silent). Respects the user's sound setting. */
  sound?: SoundName | null;
  /** Haptic to fire on a completed tap (null = none). Respects the user's haptics setting. */
  haptic?: HapticKind | null;
};

/**
 * The one press response used by every tappable card, pill and button: an immediate,
 * small scale-down that springs back on release (or a short opacity dip when the
 * system asks for reduced motion). Runs on the native driver and never changes layout.
 */
export function PressableScale({ children, style, layoutStyle, scaleTo = PRESS_SCALE.card, pressedStyle, disabled, onPress, onPressIn, onPressOut, sound = null, haptic = null, ...rest }: Props) {
  const reduced = useReducedMotion();
  const soundCtx = useSoundOptional();
  const value = useRef(new Animated.Value(0)).current;

  const handlePress = (event: GestureResponderEvent) => {
    // Feedback fires on the completed tap, so a cancelled or scrolled-off press stays silent.
    if (sound) soundCtx.play(sound);
    if (haptic) soundCtx.haptic(haptic);
    onPress?.(event);
  };

  const animateTo = (toValue: number, duration: number) =>
    Animated.timing(value, { toValue, duration, easing: toValue ? EASE.out : EASE.inOut, useNativeDriver: true }).start();

  const animatedStyle = reduced
    ? { opacity: value.interpolate({ inputRange: [0, 1], outputRange: [1, 0.82] }) }
    : { transform: [{ scale: value.interpolate({ inputRange: [0, 1], outputRange: [1, scaleTo] }) }] };

  return (
    <Pressable
      {...rest}
      disabled={disabled}
      style={layoutStyle}
      onPress={handlePress}
      onPressIn={(event) => {
        animateTo(1, DURATION.instant);
        onPressIn?.(event);
      }}
      onPressOut={(event) => {
        animateTo(0, DURATION.small);
        onPressOut?.(event);
      }}
    >
      {({ pressed }) => <Animated.View style={[style, animatedStyle, pressed && pressedStyle]}>{children}</Animated.View>}
    </Pressable>
  );
}
