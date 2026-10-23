import { useEffect, useRef, useState } from 'react';
import { Animated, Pressable, Text, View } from 'react-native';

import { DURATION, EASE } from '../../theme/motion';
import { makeStyles } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';

type Option<T extends string> = { value: T; label: string; count?: number | null };

type Props<T extends string> = {
  options: Option<T>[];
  value: T;
  onChange: (value: T) => void;
  accessibilityLabel: string;
};

/**
 * The one tab control (tournament status tabs, leaderboard periods): equal widths, one
 * height, and a single purple indicator that slides to the selected segment. The selection
 * changes the moment it is tapped; data loading is the screen's job.
 */
export function SegmentedControl<T extends string>({ options, value, onChange, accessibilityLabel }: Props<T>) {
  const styles = useStyles();
  const [width, setWidth] = useState(0);
  const index = Math.max(0, options.findIndex((option) => option.value === value));
  const position = useRef(new Animated.Value(index)).current;
  const segment = width > 0 ? (width - 8) / options.length : 0;

  useEffect(() => {
    Animated.timing(position, { toValue: index, duration: DURATION.small, easing: EASE.inOut, useNativeDriver: true }).start();
  }, [index, position]);

  return (
    <View style={styles.track} onLayout={(event) => setWidth(event.nativeEvent.layout.width)} accessibilityRole="tablist" accessibilityLabel={accessibilityLabel}>
      {segment > 0 && (
        <Animated.View
          pointerEvents="none"
          style={[styles.indicator, { width: segment, transform: [{ translateX: Animated.multiply(position, segment) }] }]}
        />
      )}
      {options.map((option) => {
        const selected = option.value === value;
        const label = option.count != null ? `${option.label} · ${option.count}` : option.label;

        return (
          <Pressable
            key={option.value}
            onPress={() => !selected && onChange(option.value)}
            accessibilityRole="tab"
            aria-selected={selected}
            accessibilityLabel={option.count != null ? `${option.label}, ${option.count}` : option.label}
            style={styles.segment}
          >
            <Text style={[styles.label, selected && styles.labelSelected]} numberOfLines={1}>
              {label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  track: {
    flexDirection: 'row',
    padding: 4,
    borderRadius: RADIUS.md,
    backgroundColor: c.surface,
    borderWidth: 1,
    borderColor: c.border,
  },
  indicator: { position: 'absolute', top: 4, bottom: 4, left: 4, borderRadius: RADIUS.sm, backgroundColor: c.brand },
  segment: { flex: 1, minHeight: 36, alignItems: 'center', justifyContent: 'center', paddingHorizontal: SPACE.xxs },
  label: { ...TYPE.bodyStrong, fontSize: 13, color: c.fg3 },
  labelSelected: { color: c.onBrand },
}));
