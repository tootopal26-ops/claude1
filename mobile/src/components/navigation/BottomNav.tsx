import type { BottomTabBarProps } from 'expo-router/tabs';
import { useEffect, useRef } from 'react';
import { Animated, Text, View, type ImageSourcePropType } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { withOpacity } from '../../theme/glass';
import { DURATION, EASE, PRESS_SCALE, useReducedMotion } from '../../theme/motion';
import { makeStyles } from '../../theme/ThemeContext';
import { ICON_SIZE, MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE } from '../../theme/tokens';
import { PressableScale } from '../interaction/PressableScale';

/**
 * The three primary destinations, in order. Nothing else is a primary tab. The artwork is
 * the user-supplied "Glossy Neon Navigation Icon Set" (mobile/assets/nav).
 */
const ITEMS: Record<string, { label: string; icon: ImageSourcePropType }> = {
  leaderboard: { label: 'Leaderboard', icon: require('../../../assets/nav/leaderboard.png') },
  index: { label: 'Home', icon: require('../../../assets/nav/home.png') },
  profile: { label: 'Profile', icon: require('../../../assets/nav/profile.png') },
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

function NavItem({ label, icon, focused, onPress, onLongPress, accessibilityLabel }: { label: string; icon: ImageSourcePropType; focused: boolean; onPress: () => void; onLongPress: () => void; accessibilityLabel: string }) {
  const styles = useStyles();
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
      {/* Selected: full colour at full size. Others: quieter (dimmed, a little smaller) but readable. */}
      <Animated.Image
        source={icon}
        resizeMode="contain"
        style={[
          styles.icon,
          {
            opacity: active.interpolate({ inputRange: [0, 1], outputRange: [0.72, 1] }),
            transform: [{ scale: active.interpolate({ inputRange: [0, 1], outputRange: [0.88, 1] }) }],
          },
        ]}
      />
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
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 2, minHeight: SIZE.touch },
  icon: { width: ICON_SIZE.nav, height: ICON_SIZE.nav },
  indicator: { position: 'absolute', top: 0, width: 20, height: 3, borderBottomLeftRadius: 2, borderBottomRightRadius: 2, backgroundColor: c.brand },
  label: { fontSize: 11, lineHeight: 14, fontWeight: '600', color: c.fg3 },
  labelActive: { color: c.fg },
}));
