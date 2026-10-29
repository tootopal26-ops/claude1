import type { BottomTabBarProps } from 'expo-router/tabs';
import { useEffect, useRef, useState } from 'react';
import { Animated, Text, View, type LayoutChangeEvent } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Defs, LinearGradient, RadialGradient, Rect, Stop } from 'react-native-svg';

import { ARTWORK, ARTWORK_SIZE, fitArtwork, type ArtworkName } from '../../theme/artwork';
import { withOpacity } from '../../theme/glass';
import { DURATION, EASE, PRESS_SCALE, SPRING, useReducedMotion } from '../../theme/motion';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE } from '../../theme/tokens';
import { useSoundOptional } from '../../sound/SoundContext';
import { PressableScale } from '../interaction/PressableScale';

/**
 * The three primary destinations, in order. Nothing else is a primary tab. The artwork is
 * the user-supplied navigation icon set (mobile/assets/nav).
 */
const ITEMS: Record<string, { label: string; artwork: ArtworkName }> = {
  leaderboard: { label: 'Leaderboard', artwork: 'navLeaderboard' },
  index: { label: 'Home', artwork: 'navHome' },
  profile: { label: 'Profile', artwork: 'navProfile' },
};

const BAR_PADDING = SPACE.xs;
/** The lit area under the selected tab, as a share of one tab's width. */
const GLOW_WIDTH_SHARE = 0.78;
const INDICATOR_TRAVEL_MS = 340;
const GLOW_EXPAND_MS = 300;

/** Space a scrolling screen must leave at its bottom so nothing hides behind the bar. */
export function useBottomNavSpace(): number {
  const insets = useSafeAreaInsets();
  return SIZE.navBar + Math.max(insets.bottom, SPACE.xs) + SPACE.xs + SPACE.md;
}

/**
 * The floating bottom navigation: Leaderboard · Home · Profile, equal items, one height.
 *
 * The selected tab is marked by one light that travels between tabs: a crisp purple line on
 * the top edge, a soft halo around the line, and a haze that spreads downward into the tab
 * and fades out — all drawn inside the bar (it is clipped there, so the light never spills
 * onto the screen). Switching tabs slides the light across (~340 ms, ease-out), dims it
 * while it travels, then lets it bloom and settle to a steady, non-pulsing glow; the new
 * icon pops and its label brightens. With reduced motion the light simply moves.
 *
 * The bar stays anchored while tabs change, sits above the system navigation area, and
 * re-tapping the current tab never pushes a duplicate screen (the screen scrolls to top).
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
        { bottom: Math.max(insets.bottom, SPACE.xs) + SPACE.xs },
        { opacity: entrance, transform: [{ translateY: entrance.interpolate({ inputRange: [0, 1], outputRange: [reduced ? 0 : 6, 0] }) }] },
      ]}
    >
      <View style={styles.bar} accessibilityRole="tablist">
        <SelectionLight index={selected} count={routes.length} reduced={reduced} />
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
              artwork={item.artwork}
              focused={focused}
              reduced={reduced}
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

/**
 * The travelling light. Its position animates on the native driver (translateX), and its
 * strength and bloom are opacity/scale only — cheap on low-end Android, no blur filters.
 */
function SelectionLight({ index, count, reduced }: { index: number; count: number; reduced: boolean }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const [barWidth, setBarWidth] = useState(0);
  const position = useRef(new Animated.Value(index)).current;
  const strength = useRef(new Animated.Value(1)).current;
  const bloom = useRef(new Animated.Value(1)).current;
  const first = useRef(true);

  useEffect(() => {
    if (first.current) {
      first.current = false;
      position.setValue(index);
      return;
    }

    if (reduced) {
      position.setValue(index);
      strength.setValue(1);
      bloom.setValue(1);
      return;
    }

    // Slide across while dimmed (the old tab's light fading out), then bloom and settle on the new tab.
    Animated.parallel([
      Animated.timing(position, { toValue: index, duration: INDICATOR_TRAVEL_MS, easing: EASE.out, useNativeDriver: true }),
      Animated.sequence([
        Animated.timing(strength, { toValue: 0.45, duration: 110, easing: EASE.out, useNativeDriver: true }),
        Animated.timing(strength, { toValue: 1, duration: GLOW_EXPAND_MS - 60, easing: EASE.out, useNativeDriver: true }),
      ]),
      Animated.sequence([
        Animated.timing(bloom, { toValue: 0.6, duration: 110, easing: EASE.out, useNativeDriver: true }),
        Animated.timing(bloom, { toValue: 1.12, duration: GLOW_EXPAND_MS - 100, easing: EASE.out, useNativeDriver: true }),
        Animated.spring(bloom, { ...SPRING, toValue: 1 }),
      ]),
    ]).start();
  }, [index, reduced, position, strength, bloom]);

  const onLayout = (event: LayoutChangeEvent) => setBarWidth(event.nativeEvent.layout.width);
  const itemWidth = barWidth > 0 ? (barWidth - BAR_PADDING * 2) / Math.max(count, 1) : 0;
  const glowWidth = itemWidth * GLOW_WIDTH_SHARE;
  const translateX = position.interpolate({
    inputRange: [0, Math.max(count - 1, 1)],
    outputRange: [BAR_PADDING + (itemWidth - glowWidth) / 2, BAR_PADDING + itemWidth * Math.max(count - 1, 1) + (itemWidth - glowWidth) / 2],
  });

  return (
    <View style={styles.lightLayer} onLayout={onLayout} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {glowWidth > 0 ? (
        <Animated.View style={[styles.light, { width: glowWidth, transform: [{ translateX }] }]}>
          {/* Diffuse haze spreading down from the line, brightest at the top. The gradient box is
              twice the bar's height (the bar clips it), so a standard circular `r` gradient — which
              renders the same on Android and web — reaches the bar's bottom edge. */}
          <Animated.View style={[styles.haze, { opacity: strength, transform: [{ scaleY: bloom }] }]}>
            <Svg width="100%" height="100%" preserveAspectRatio="none">
              <Defs>
                <RadialGradient id="mm-nav-haze" cx="50%" cy="0%" r="50%" fx="50%" fy="0%">
                  <Stop offset="0" stopColor={colors.brandText} stopOpacity={0.7} />
                  <Stop offset="0.25" stopColor={colors.brand} stopOpacity={0.5} />
                  <Stop offset="0.6" stopColor={colors.brand} stopOpacity={0.18} />
                  <Stop offset="1" stopColor={colors.brand} stopOpacity={0} />
                </RadialGradient>
              </Defs>
              <Rect x="0" y="0" width="100%" height="100%" fill="url(#mm-nav-haze)" />
            </Svg>
          </Animated.View>
          {/* Soft halo hugging the line (same technique, a short box). */}
          <Animated.View style={[styles.halo, { opacity: strength }]}>
            <Svg width="100%" height="100%" preserveAspectRatio="none">
              <Defs>
                <RadialGradient id="mm-nav-halo" cx="50%" cy="0%" r="50%" fx="50%" fy="0%">
                  <Stop offset="0" stopColor={colors.brandText} stopOpacity={0.95} />
                  <Stop offset="0.5" stopColor={colors.brand} stopOpacity={0.45} />
                  <Stop offset="1" stopColor={colors.brand} stopOpacity={0} />
                </RadialGradient>
              </Defs>
              <Rect x="0" y="0" width="100%" height="100%" fill="url(#mm-nav-halo)" />
            </Svg>
          </Animated.View>
          {/* The crisp line: a bright core fading to the brand colour at its ends. */}
          <View style={styles.line}>
            <Svg width="100%" height="100%" preserveAspectRatio="none">
              <Defs>
                <LinearGradient id="mm-nav-line" x1="0" y1="0" x2="1" y2="0">
                  <Stop offset="0" stopColor={colors.brand} stopOpacity={0.15} />
                  <Stop offset="0.25" stopColor={colors.brand} stopOpacity={1} />
                  <Stop offset="0.5" stopColor={colors.brandText} stopOpacity={1} />
                  <Stop offset="0.75" stopColor={colors.brand} stopOpacity={1} />
                  <Stop offset="1" stopColor={colors.brand} stopOpacity={0.15} />
                </LinearGradient>
              </Defs>
              <Rect x="0" y="0" width="100%" height="100%" rx="1.5" fill="url(#mm-nav-line)" />
            </Svg>
          </View>
        </Animated.View>
      ) : null}
    </View>
  );
}

function NavItem({
  label,
  artwork,
  focused,
  reduced,
  onPress,
  onLongPress,
  accessibilityLabel,
}: {
  label: string;
  artwork: ArtworkName;
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
  const iconSize = fitArtwork(artwork, ARTWORK_SIZE.nav);

  useEffect(() => {
    Animated.timing(active, { toValue: focused ? 1 : 0, duration: DURATION.card, easing: EASE.inOut, useNativeDriver: true }).start();
    // The icon pops when its tab becomes selected (not on first render, not with reduced motion).
    if (focused && !wasFocused.current && !reduced) {
      pop.setValue(1);
      Animated.sequence([
        Animated.timing(pop, { toValue: 1.14, duration: 130, easing: EASE.out, useNativeDriver: true }),
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
      scaleTo={PRESS_SCALE.icon}
      layoutStyle={styles.itemLayout}
      style={styles.item}
    >
      {/* Selected: full colour at full size. Others: quieter (dimmed, a little smaller) but readable. */}
      <Animated.Image
        source={ARTWORK[artwork].source}
        resizeMode="contain"
        style={[
          iconSize,
          {
            opacity: active.interpolate({ inputRange: [0, 1], outputRange: [0.72, 1] }),
            transform: [{ scale: Animated.multiply(active.interpolate({ inputRange: [0, 1], outputRange: [0.88, 1] }), pop) }],
          },
        ]}
      />
      <Animated.Text style={[styles.label, focused && styles.labelActive, { opacity: active.interpolate({ inputRange: [0, 1], outputRange: [0.85, 1] }) }]} numberOfLines={1}>
        {label}
      </Animated.Text>
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
    paddingHorizontal: BAR_PADDING,
    // Keeps the selection light inside the bar's rounded shape.
    overflow: 'hidden',
  },
  lightLayer: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  light: { position: 'absolute', top: 0, bottom: 0, left: 0 },
  // Twice the bar's height; scaleY grows from the top edge, so the haze stays anchored to the line.
  haze: { position: 'absolute', left: 0, right: 0, top: 0, height: SIZE.navBar * 2, transformOrigin: 'top' },
  halo: { position: 'absolute', left: '4%', right: '4%', top: 0, height: 36 },
  line: { position: 'absolute', left: '10%', right: '10%', top: 0, height: 3 },
  itemLayout: { flex: 1 },
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 2, minHeight: SIZE.touch },
  label: { fontSize: 11, lineHeight: 14, fontWeight: '600', color: c.fg3 },
  labelActive: { color: c.fg },
}));
