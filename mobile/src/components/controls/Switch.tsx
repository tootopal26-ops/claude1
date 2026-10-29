import { useEffect, useRef } from 'react';
import { Animated, Pressable } from 'react-native';

import { useSoundOptional } from '../../sound/SoundContext';
import { DURATION, EASE } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';

type Props = {
  value: boolean;
  onValueChange: (value: boolean) => void;
  accessibilityLabel: string;
  disabled?: boolean;
};

/**
 * A small on/off switch: a pill track and a thumb that slides across, brand-coloured when on.
 * Flipping it plays the precise toggle tick (and a light haptic) through the shared sound engine,
 * so every toggle in the app feels the same. Respects the user's sound/haptics settings.
 */
export function Switch({ value, onValueChange, accessibilityLabel, disabled }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const sound = useSoundOptional();
  const progress = useRef(new Animated.Value(value ? 1 : 0)).current;

  useEffect(() => {
    Animated.timing(progress, { toValue: value ? 1 : 0, duration: DURATION.small, easing: EASE.inOut, useNativeDriver: false }).start();
  }, [value, progress]);

  return (
    <Pressable
      accessibilityRole="switch"
      accessibilityState={{ checked: value, disabled }}
      accessibilityLabel={accessibilityLabel}
      disabled={disabled}
      hitSlop={8}
      onPress={() => {
        sound.playToggle();
        onValueChange(!value);
      }}
      style={disabled ? styles.disabled : undefined}
    >
      <Animated.View
        style={[
          styles.track,
          { backgroundColor: progress.interpolate({ inputRange: [0, 1], outputRange: [colors.surface3, colors.brand] }) },
        ]}
      >
        <Animated.View style={[styles.thumb, { transform: [{ translateX: progress.interpolate({ inputRange: [0, 1], outputRange: [2, 22] }) }] }]} />
      </Animated.View>
    </Pressable>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  track: { width: 48, height: 28, borderRadius: 14, justifyContent: 'center' },
  thumb: { width: 24, height: 24, borderRadius: 12, backgroundColor: c.onBrand },
  disabled: { opacity: 0.45 },
}));
