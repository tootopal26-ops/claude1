import type { BottomTabBarProps } from 'expo-router/tabs';
import { useEffect, useRef, useState } from 'react';
import { Animated, Text, View, type LayoutChangeEvent } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Defs, RadialGradient, Rect, Stop } from 'react-native-svg';

import { useSoundOptional } from '../../sound/SoundContext';
import { DURATION, EASE, SPRING, useReducedMotion } from '../../theme/motion';
import { makeStyles } from '../../theme/ThemeContext';
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

/** The dark-grey pill behind the selected icon — fixed size, not proportional to the item. */
const PILL_WIDTH = 52;
const PILL_HEIGHT = 32;
const PILL_TOP = (SIZE.navBar - PILL_HEIGHT) / 2 - 7;
/** The purple indicator line above the selected icon — a share of the item's own width. */
const LINE_WIDTH_SHARE = 0.38;
const LINE_HEIGHT = 3;
const LINE_TOP = 7;
/** The soft downward light: wider than the line (a little horizontal spread), fading out
 *  by the time it reaches the icon rather than washing over the whole pill. */
const LIGHT_WIDTH_SHARE = 0.68;
const LIGHT_TOP = LINE_TOP;
const LIGHT_HEIGHT = 20;
const PURPLE = '#A855F7';
/** Smooth, spring-based, never bouncy. */
const ICON_MORPH_MS = 300;
const POP_OUT_MS = 130;
const POP_PEAK_SCALE = 1.12;

/** Space a scrolling screen must leave at its bottom so nothing hides behind the bar. */
export function useBottomNavSpace(): number {
  const insets = useSafeAreaInsets();
  return SIZE.navBar + insets.bottom + SPACE.sm;
}

/**
 * The bottom navigation bar: Leaderboard · Spin · Home · Profile — a flat, full-width bar on
 * the app's dark canvas. Unselected icons are hollow white outlines; the selected icon morphs
 * into a solid white silhouette inside a subtle dark-grey pill, marked above by a thin purple
 * indicator line with a soft purple light falling down toward it. Both the pill and the
 * indicator travel together to the newly selected item on one spring. Re-tapping the current
 * tab never pushes a duplicate screen (the screen scrolls to top).
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
          <SelectionIndicator index={selected} count={routes.length} reduced={reduced} />
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
 * The travelling selection mark behind/above the selected item: the dark-grey pill, the
 * purple line above the icon, and the soft purple light falling from that line toward the
 * icon. All three share one spring-animated horizontal position (native driver), so they
 * arrive together — no glow around the icon itself, no circle, just light falling from the
 * line down toward it.
 */
function SelectionIndicator({ index, count, reduced }: { index: number; count: number; reduced: boolean }) {
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
    // A small pop while everything settles into place — never a visible bounce past 1.
    scale.setValue(0.9);
    Animated.spring(scale, { ...SPRING, toValue: 1 }).start();
  }, [index, reduced, position, scale]);

  const onLayout = (event: LayoutChangeEvent) => setBarWidth(event.nativeEvent.layout.width);
  const itemWidth = barWidth > 0 ? barWidth / Math.max(count, 1) : 0;
  const lineWidth = itemWidth * LINE_WIDTH_SHARE;
  const lightWidth = itemWidth * LIGHT_WIDTH_SHARE;

  // Each shape has its own width, so each gets its own centred travel path over the same item slots.
  const centerX = (width: number) =>
    position.interpolate({
      inputRange: [0, Math.max(count - 1, 1)],
      outputRange: [(itemWidth - width) / 2, itemWidth * Math.max(count - 1, 1) + (itemWidth - width) / 2],
    });

  if (itemWidth <= 0) {
    return <View style={styles.indicatorLayer} onLayout={onLayout} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants" />;
  }

  return (
    <View style={styles.indicatorLayer} onLayout={onLayout} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <Animated.View style={[styles.pill, { width: PILL_WIDTH, transform: [{ translateX: centerX(PILL_WIDTH) }, { scale }] }]} />
      <Animated.View style={[styles.light, { width: lightWidth, transform: [{ translateX: centerX(lightWidth) }] }]}>
        <Svg width="100%" height="100%" preserveAspectRatio="none">
          <Defs>
            {/* Anchored at the top edge only (cy 0%), so the glow radiates down and slightly
                out — never a ring or circle around the icon. */}
            <RadialGradient id="mm-nav-light" cx="50%" cy="0%" r="75%" fx="50%" fy="0%">
              <Stop offset="0" stopColor={PURPLE} stopOpacity={0.3} />
              <Stop offset="0.5" stopColor={PURPLE} stopOpacity={0.12} />
              <Stop offset="1" stopColor={PURPLE} stopOpacity={0} />
            </RadialGradient>
          </Defs>
          <Rect x="0" y="0" width="100%" height="100%" fill="url(#mm-nav-light)" />
        </Svg>
      </Animated.View>
      <Animated.View style={[styles.line, { width: lineWidth, borderRadius: LINE_HEIGHT / 2, transform: [{ translateX: centerX(lineWidth) }] }]} />
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
    // Scale from 1.0 to 1.12 and settle back to 1.0 — only when this tab just became selected.
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
            morphs instead of popping, and the previous tab's icon reverses the same way.
            Both layers sit above the purple light, which never reduces their visibility. */}
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

const useStyles = makeStyles(() => ({
  wrap: { position: 'absolute', left: 0, right: 0, bottom: 0, alignItems: 'center' },
  bar: {
    width: '100%',
    maxWidth: MAX_CONTENT_WIDTH,
    // The app's fixed dark canvas colour — this bar keeps it regardless of the selected
    // colour theme, per the nav redesign spec's exact reference.
    backgroundColor: '#17111F',
    borderTopLeftRadius: RADIUS.lg,
    borderTopRightRadius: RADIUS.lg,
    // Clips the travelling pill/light/line to the bar's shape.
    overflow: 'hidden',
  },
  row: { height: SIZE.navBar, flexDirection: 'row', alignItems: 'stretch' },
  indicatorLayer: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  pill: { position: 'absolute', top: PILL_TOP, left: 0, height: PILL_HEIGHT, borderRadius: RADIUS.pill, backgroundColor: 'rgba(255,255,255,0.14)' },
  light: { position: 'absolute', top: LIGHT_TOP, left: 0, height: LIGHT_HEIGHT },
  line: { position: 'absolute', top: LINE_TOP, left: 0, height: LINE_HEIGHT, backgroundColor: PURPLE },
  itemLayout: { flex: 1 },
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3, minHeight: SIZE.touch },
  iconSlot: { width: 24, height: 24, alignItems: 'center', justifyContent: 'center' },
  iconLayer: { position: 'absolute', alignItems: 'center', justifyContent: 'center' },
  label: { fontSize: 11, lineHeight: 14, color: 'rgba(255,255,255,0.78)' },
}));
