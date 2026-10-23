import type { BottomTabBarProps } from 'expo-router/tabs';
import { useEffect, useRef } from 'react';
import { Animated, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { withOpacity } from '../../theme/glass';
import { DURATION, EASE, PRESS_SCALE, useReducedMotion } from '../../theme/motion';
import { Icon, type IconName } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE } from '../../theme/tokens';
import { PressableScale } from '../interaction/PressableScale';

/** The three primary destinations, in order. Nothing else is a primary tab. */
const ITEMS: Record<string, { label: string; icon: IconName }> = {
  leaderboard: { label: 'Leaderboard', icon: 'trophy' },
  index: { label: 'Home', icon: 'home' },
  profile: { label: 'Profile', icon: 'user' },
};

/** Space a scrolling screen must leave at its bottom so nothing hides behind the bar. */
export function useBottomNavSpace(): number {
  const insets = useSafeAreaInsets();
  return SIZE.navBar + Math.max(insets.bottom, SPACE.xs) + SPACE.xs + SPACE.md;
}

/**
 * The floating bottom navigation: Leaderboard · Home · Profile, equal items, one height.
 * It stays anchored while tabs change (only the selected state animates), fades in once
 * when the app opens, sits above the system navigation area, and re-tapping the current
 * tab never pushes a duplicate screen (the screen scrolls back to the top instead).
 */
export function BottomNav({ state, descriptors, emitter, navigateToTab }: BottomTabBarProps) {
  const styles = useStyles();
  const insets = useSafeAreaInsets();
  const reduced = useReducedMotion();
  const entrance = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    Animated.timing(entrance, { toValue: 1, duration: DURATION.screen, easing: EASE.out, useNativeDriver: true }).start();
  }, [entrance]);

  return (
    <Animated.View
      style={[
        styles.wrap,
        { bottom: Math.max(insets.bottom, SPACE.xs) + SPACE.xs },
        { opacity: entrance, transform: [{ translateY: entrance.interpolate({ inputRange: [0, 1], outputRange: [reduced ? 0 : 6, 0] }) }] },
      ]}
    >
      <View style={styles.bar} accessibilityRole="tablist">
        {state.routes.map((route, index) => {
          const item = ITEMS[route.name];
          if (!item) return null;
          const focused = state.index === index;

          const onPress = () => {
            const event = emitter.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            if (!focused && !event.defaultPrevented) {
              navigateToTab(route.key);
            }
          };

          return (
            <NavItem
              key={route.key}
              label={item.label}
              icon={item.icon}
              focused={focused}
              onPress={onPress}
              onLongPress={() => emitter.emit({ type: 'tabLongPress', target: route.key })}
              accessibilityLabel={descriptors[route.key]?.options.tabBarAccessibilityLabel ?? item.label}
            />
          );
        })}
      </View>
    </Animated.View>
  );
}

function NavItem({ label, icon, focused, onPress, onLongPress, accessibilityLabel }: { label: string; icon: IconName; focused: boolean; onPress: () => void; onLongPress: () => void; accessibilityLabel: string }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const active = useRef(new Animated.Value(focused ? 1 : 0)).current;

  useEffect(() => {
    Animated.timing(active, { toValue: focused ? 1 : 0, duration: DURATION.small, easing: EASE.inOut, useNativeDriver: true }).start();
  }, [focused, active]);

  return (
    <PressableScale
      onPress={onPress}
      onLongPress={onLongPress}
      accessibilityRole="tab"
      aria-selected={focused}
      accessibilityLabel={accessibilityLabel}
      scaleTo={PRESS_SCALE.icon}
      layoutStyle={styles.itemLayout}
      style={styles.item}
    >
      <Animated.View style={[styles.indicator, { opacity: active, transform: [{ scaleX: active.interpolate({ inputRange: [0, 1], outputRange: [0.4, 1] }) }] }]} />
      <Icon name={icon} size={SIZE.navIcon} color={focused ? colors.brandText : colors.fg3} accent={focused ? colors.brandText : colors.fg3} />
      <Text style={[styles.label, focused && styles.labelActive]} numberOfLines={1}>
        {label}
      </Text>
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  wrap: { position: 'absolute', left: SPACE.md, right: SPACE.md, alignItems: 'center' },
  bar: {
    width: '100%',
    maxWidth: MAX_CONTENT_WIDTH - SPACE.md * 2,
    height: SIZE.navBar,
    flexDirection: 'row',
    alignItems: 'stretch',
    borderRadius: RADIUS.xl,
    backgroundColor: withOpacity(c.surface2, 0.94),
    borderWidth: 1,
    borderColor: c.border,
    paddingHorizontal: SPACE.xs,
  },
  itemLayout: { flex: 1 },
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: SPACE.xxs, minHeight: SIZE.touch },
  indicator: { position: 'absolute', top: 0, width: 20, height: 3, borderBottomLeftRadius: 2, borderBottomRightRadius: 2, backgroundColor: c.brand },
  label: { fontSize: 11, lineHeight: 14, fontWeight: '600', color: c.fg3 },
  labelActive: { color: c.fg },
}));
