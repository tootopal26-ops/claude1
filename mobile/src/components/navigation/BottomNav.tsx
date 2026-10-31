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

// The icon/label block's own layout — kept as named constants (not literals re-typed in the
// styles below) so the geometry math below and the actual rendered sizes can never drift apart.
const ICON_SIZE = 24;
const ITEM_GAP = 3;
const LABEL_LINE_HEIGHT = 14;
const CONTENT_BLOCK_HEIGHT = ICON_SIZE + ITEM_GAP + LABEL_LINE_HEIGHT;

/**
 * Extra breathing room at the top of the bar, reserved for the indicator line and its light —
 * added to the row's own height (not just padding), so the icon/label block — which `.item`
 * centers in whatever height the row has — sits lower, with real space above it. Because
 * centering splits the added height between top and bottom, the icon only moves down by half
 * of this; the formulas below account for that rather than assuming the full amount.
 */
const INDICATOR_ZONE = 24;
const BAR_ROW_HEIGHT = SIZE.navBar + INDICATOR_ZONE;
/** Where the icon itself actually lands once centered in the (taller) row — the one number
 *  everything else here (the pill, the line, the light) is positioned relative to. */
const ICON_TOP = (BAR_ROW_HEIGHT - CONTENT_BLOCK_HEIGHT) / 2;

/** The dark-grey pill behind the selected icon — fixed size, not proportional to the item,
 *  centred on the icon's own vertical centre so it fully surrounds it. */
const PILL_WIDTH = 52;
const PILL_HEIGHT = 32;
const PILL_TOP = ICON_TOP - (PILL_HEIGHT - ICON_SIZE) / 2;
/** The purple indicator line above the selected icon — a share of the item's own width. */
const LINE_WIDTH_SHARE = 0.4;
const LINE_HEIGHT = 3;
const LINE_TOP = 4;
/** Kept clear of both the line above and the pill below, so the gap is never accidentally
 *  closed by a future tweak to either one. */
const LIGHT_GAP_BEFORE_PILL = 3;
/**
 * The soft downward light. Its own box is deliberately much bigger than the glow it draws —
 * the same trick AmbientGlow uses — so the radial gradient's `rx`/`ry` (never a single `r`,
 * which distorts into a hard-edged ellipse on a box this shape) can fade all the way to
 * fully transparent well inside the box, with margin to spare. That margin is what keeps the
 * glow looking like soft falling light instead of a visible rounded-rectangle tile. It stops
 * well short of the pill (and so of the icon inside it), leaving a clear, visible gap — the
 * light illuminates the space above the icon, it doesn't wash over it.
 */
const LIGHT_WIDTH_SHARE = 1.6;
const LIGHT_TOP = LINE_TOP;
const LIGHT_HEIGHT = PILL_TOP - LINE_TOP - LIGHT_GAP_BEFORE_PILL;
const PURPLE = '#A855F7';
/** Smooth, spring-based, never bouncy. */
const ICON_MORPH_MS = 300;
const POP_OUT_MS = 130;
const POP_PEAK_SCALE = 1.12;

/** Space a scrolling screen must leave at its bottom so nothing hides behind the bar. */
export function useBottomNavSpace(): number {
  const insets = useSafeAreaInsets();
  return BAR_ROW_HEIGHT + insets.bottom + SPACE.sm;
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
        <Svg width="100%" height="100%">
          <Defs>
            {/* Anchored at the top edge only (cy 0%), with separate x/y radii well short of
                the box's own edges (never a single `r`, which stretches into a hard-edged
                ellipse here) — same recipe as AmbientGlow. The box is generously bigger than
                the visible glow, so the fade to fully transparent happens with room to spare
                and never reads as a rectangle. */}
            <RadialGradient id="mm-nav-light" cx="50%" cy="0%" rx="32%" ry="92%" fx="50%" fy="0%">
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
        {/* A true crossfade: the outline's opacity is 1-active, the filled icon's is active,
            so exactly one is ever visible once settled (at active 0 or 1) and only mid-tap do
            both partially show, which is the crossfade itself, not a bug. Earlier the outline
            layer had no opacity animation at all, so both icons stayed fully visible, stacked,
            once a tab was selected — that's the "duplicate icon" this fixes. */}
        <Animated.View style={[styles.iconLayer, { opacity: active.interpolate({ inputRange: [0, 1], outputRange: [1, 0] }) }]}>
          <NavIcon name={icon} filled={false} size={24} />
        </Animated.View>
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
  row: { height: BAR_ROW_HEIGHT, flexDirection: 'row', alignItems: 'stretch' },
  indicatorLayer: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  pill: { position: 'absolute', top: PILL_TOP, left: 0, height: PILL_HEIGHT, borderRadius: RADIUS.pill, backgroundColor: 'rgba(255,255,255,0.14)' },
  light: { position: 'absolute', top: LIGHT_TOP, left: 0, height: LIGHT_HEIGHT },
  line: { position: 'absolute', top: LINE_TOP, left: 0, height: LINE_HEIGHT, backgroundColor: PURPLE },
  itemLayout: { flex: 1 },
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: ITEM_GAP, minHeight: SIZE.touch },
  iconSlot: { width: ICON_SIZE, height: ICON_SIZE, alignItems: 'center', justifyContent: 'center' },
  iconLayer: { position: 'absolute', alignItems: 'center', justifyContent: 'center' },
  label: { fontSize: 11, lineHeight: LABEL_LINE_HEIGHT, color: 'rgba(255,255,255,0.78)' },
}));
