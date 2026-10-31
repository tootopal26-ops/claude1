import type { BottomTabBarProps } from 'expo-router/tabs';
import { useEffect, useRef, useState } from 'react';
import { Animated, Text, View, type LayoutChangeEvent } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useSoundOptional } from '../../sound/SoundContext';
import { DURATION, EASE, SPRING, useReducedMotion } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE } from '../../theme/tokens';
import { PressableScale } from '../interaction/PressableScale';
import { NavIcon, type NavIconName } from './NavIcons';

/**
 * The four primary destinations, in order. Nothing else is a primary tab.
 */
const ITEMS: Record<string, { label: string; icon: NavIconName }> = {
  leaderboard: { label: 'Leaderboard', icon: 'leaderboard' },
  spin: { label: 'Spin', icon: 'spin' },
  index: { label: 'Home', icon: 'home' },
  profile: { label: 'Profile', icon: 'profile' },
};

/** The pill that sits behind the selected icon — fixed size, not proportional to the item. */
const PILL_WIDTH = 52;
const PILL_HEIGHT = 32;
/** Smooth, spring-based, never bouncy: within the requested 250–350ms window. */
const ICON_MORPH_MS = 300;
const POP_OUT_MS = 130;
const POP_PEAK_SCALE = 1.15;

/** Space a scrolling screen must leave at its bottom so nothing hides behind the bar. */
export function useBottomNavSpace(): number {
  const insets = useSafeAreaInsets();
  return SIZE.navBar + insets.bottom + SPACE.sm;
}

/**
 * The bottom navigation bar: Leaderboard · Spin · Home · Profile — a WhatsApp-style flat,
 * full-width bar. Unselected icons are hollow white outlines on transparent backgrounds;
 * the selected icon morphs into a solid white silhouette inside a subtle dark-grey pill
 * that slides to follow it. No gradients, glow or glass — a single flat fill, per the nav
 * redesign spec. Re-tapping the current tab never pushes a duplicate screen (the screen
 * scrolls to top) and nothing here moves on 60fps-cheap properties other than opacity,
 * scale and one horizontal translate (all native-driven).
 */
export function BottomNav({ state, descriptors, emitter, navigateToTab }: BottomTabBarProps) {
  const styles = useStyles();
  const insets = useSafeAreaInsets();
  const reduced = useReducedMotion();
  const sound = useSoundOptional();
  const entrance = useRef(new Animated.Value(0)).current;
  const routes = state.routes.filter((route) => ITEMS[route.name]);
  const selected = Math.max(0, routes.findIndex((route) => route.key === state.routes[state.index]?.key));

  useEffect(() => {
    Animated.timing(entrance, { toValue: 1, duration: DURATION.screen, easing: EASE.out, useNativeDriver: true }).start();
  }, [entrance]);

  return (
    <Animated.View
      style={[
        styles.wrap,
        { opacity: entrance, transform: [{ translateY: entrance.interpolate({ inputRange: [0, 1], outputRange: [reduced ? 0 : 10, 0] }) }] },
      ]}
    >
      <View style={[styles.bar, { paddingBottom: insets.bottom }]} accessibilityRole="tablist">
        <View style={styles.row}>
          <SelectionPill index={selected} count={routes.length} reduced={reduced} />
          {routes.map((route, index) => {
            const item = ITEMS[route.name];
            const focused = index === selected;

            const onPress = () => {
              const event = emitter.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
              if (!focused && !event.defaultPrevented) {
                sound.playSelection();
                navigateToTab(route.key);
              }
            };

            return (
              <NavItem
                key={route.key}
                label={item.label}
                icon={item.icon}
                focused={focused}
                reduced={reduced}
                onPress={onPress}
                onLongPress={() => emitter.emit({ type: 'tabLongPress', target: route.key })}
                accessibilityLabel={descriptors[route.key]?.options.tabBarAccessibilityLabel ?? item.label}
              />
            );
          })}
        </View>
      </View>
    </Animated.View>
  );
}

/**
 * The travelling pill behind the selected item. Its position animates with a spring (native
 * driver, translateX only) so it settles without overshoot — no glow, no haze, just a flat
 * dark-grey rounded rect.
 */
function SelectionPill({ index, count, reduced }: { index: number; count: number; reduced: boolean }) {
  const styles = useStyles();
  const [barWidth, setBarWidth] = useState(0);
  const position = useRef(new Animated.Value(index)).current;
  const scale = useRef(new Animated.Value(1)).current;
  const first = useRef(true);

  useEffect(() => {
    if (first.current) {
      first.current = false;
      position.setValue(index);
      return;
    }

    if (reduced) {
      position.setValue(index);
      return;
    }

    Animated.spring(position, { ...SPRING, toValue: index }).start();
    // A small pop while the pill settles into place — never a visible bounce past 1.
    scale.setValue(0.9);
    Animated.spring(scale, { ...SPRING, toValue: 1 }).start();
  }, [index, reduced, position, scale]);

  const onLayout = (event: LayoutChangeEvent) => setBarWidth(event.nativeEvent.layout.width);
  const itemWidth = barWidth > 0 ? barWidth / Math.max(count, 1) : 0;
  const translateX = position.interpolate({
    inputRange: [0, Math.max(count - 1, 1)],
    outputRange: [(itemWidth - PILL_WIDTH) / 2, itemWidth * Math.max(count - 1, 1) + (itemWidth - PILL_WIDTH) / 2],
  });

  return (
    <View style={styles.pillLayer} onLayout={onLayout} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {itemWidth > 0 ? <Animated.View style={[styles.pill, { transform: [{ translateX }, { scale }] }]} /> : null}
    </View>
  );
}

function NavItem({
  label,
  icon,
  focused,
  reduced,
  onPress,
  onLongPress,
  accessibilityLabel,
}: {
  label: string;
  icon: NavIconName;
  focused: boolean;
  reduced: boolean;
  onPress: () => void;
  onLongPress: () => void;
  accessibilityLabel: string;
}) {
  const styles = useStyles();
  const active = useRef(new Animated.Value(focused ? 1 : 0)).current;
  const pop = useRef(new Animated.Value(1)).current;
  const wasFocused = useRef(focused);

  useEffect(() => {
    Animated.timing(active, { toValue: focused ? 1 : 0, duration: ICON_MORPH_MS, easing: EASE.inOut, useNativeDriver: true }).start();
    // Scale from 1.0 to 1.15 and settle back to 1.0 — only when this tab just became selected.
    if (focused && !wasFocused.current && !reduced) {
      pop.setValue(1);
      Animated.sequence([
        Animated.timing(pop, { toValue: POP_PEAK_SCALE, duration: POP_OUT_MS, easing: EASE.out, useNativeDriver: true }),
        Animated.spring(pop, { ...SPRING, toValue: 1 }),
      ]).start();
    }
    wasFocused.current = focused;
  }, [focused, reduced, active, pop]);

  return (
    <PressableScale
      onPress={onPress}
      onLongPress={onLongPress}
      accessibilityRole="tab"
      aria-selected={focused}
      accessibilityLabel={accessibilityLabel}
      sound={null}
      haptic={null}
      layoutStyle={styles.itemLayout}
      style={styles.item}
    >
      <Animated.View style={[styles.iconSlot, { transform: [{ scale: pop }] }]}>
        {/* Outline underneath, filled on top — opacity crossfades between them so the glyph
            morphs instead of popping, and the previous tab's icon reverses the same way. */}
        <View style={styles.iconLayer}>
          <NavIcon name={icon} filled={false} size={24} />
        </View>
        <Animated.View style={[styles.iconLayer, { opacity: active }]}>
          <NavIcon name={icon} filled size={24} />
        </Animated.View>
      </Animated.View>
      <Animated.Text
        style={[styles.label, { opacity: active.interpolate({ inputRange: [0, 1], outputRange: [0.72, 1] }), fontWeight: focused ? '700' : '600' }]}
        numberOfLines={1}
      >
        {label}
      </Animated.Text>
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  wrap: { position: 'absolute', left: 0, right: 0, bottom: 0, alignItems: 'center' },
  bar: {
    width: '100%',
    maxWidth: MAX_CONTENT_WIDTH,
    backgroundColor: c.sidebar,
    borderTopLeftRadius: RADIUS.lg,
    borderTopRightRadius: RADIUS.lg,
    // Clips the travelling pill to the bar's shape; there is nothing else here that needs
    // to spill past it (no glow, no gradient).
    overflow: 'hidden',
  },
  row: { height: SIZE.navBar, flexDirection: 'row', alignItems: 'stretch' },
  pillLayer: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  pill: { position: 'absolute', top: (SIZE.navBar - PILL_HEIGHT) / 2 - 7, left: 0, width: PILL_WIDTH, height: PILL_HEIGHT, borderRadius: RADIUS.pill, backgroundColor: 'rgba(255,255,255,0.14)' },
  itemLayout: { flex: 1 },
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3, minHeight: SIZE.touch },
  iconSlot: { width: 24, height: 24, alignItems: 'center', justifyContent: 'center' },
  iconLayer: { position: 'absolute', alignItems: 'center', justifyContent: 'center' },
  label: { fontSize: 11, lineHeight: 14, color: 'rgba(255,255,255,0.78)' },
}));
