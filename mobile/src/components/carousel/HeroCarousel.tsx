import { useIsFocused } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Animated, AppState, PanResponder, Pressable, Text, View } from 'react-native';
import Svg, { Defs, LinearGradient, Rect, Stop } from 'react-native-svg';

import { DURATION, EASE, useReducedMotion } from '../../theme/motion';
import { glassScrim } from '../../theme/glass';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import { BrandLogo } from '../BrandLogo';
import { Skeleton } from '../states/States';

export type HeroBanner = { id: number; title: string | null; image_url: string | null; link_url?: string | null };

type Props = {
  /** null while loading. */
  banners: HeroBanner[] | null;
  width: number;
  /** Called only for banners that carry a link. */
  onOpenLink?: (banner: HeroBanner) => void;
  /** e.g. "Featured" or "Leaderboard banners" — read before the position. */
  label: string;
  /** Shown in the branded frame when no banner is configured. */
  emptyText: string;
};

const AUTO_ADVANCE_MS = 5000;
const SWIPE_MS = 280;

const mod = (value: number, count: number) => ((value % count) + count) % count;

/**
 * The 16:9 banner carousel shared by Home and the Leaderboard. Pictures fill the frame
 * (`cover`) without stretching. With several banners it advances every five seconds from
 * right to left — the outgoing picture slides out and dims while the next settles in sharp,
 * with a brief softening of the frame edges during the change only. One timer drives it;
 * it stops while the screen is not focused, the app is in the background, the player is
 * swiping, or the system asks for reduced motion. Swiping always works. A banner with a
 * link opens it on tap; one without a link does nothing.
 */
export function HeroCarousel({ banners, width, onOpenLink, label, emptyText }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const height = Math.round((width * 9) / 16);
  const count = banners?.length ?? 0;
  const reduced = useReducedMotion();
  const focused = useIsFocused();
  const [appActive, setAppActive] = useState(AppState.currentState === 'active');
  const [base, setBase] = useState(0);
  const [dragging, setDragging] = useState(false);
  const position = useRef(new Animated.Value(0)).current;
  const edge = useRef(new Animated.Value(0)).current;
  const settled = useRef(0);
  const animating = useRef(false);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => setAppActive(state === 'active'));
    return () => subscription.remove();
  }, []);

  // New data: start again from the first banner.
  useEffect(() => {
    position.setValue(0);
    settled.current = 0;
    setBase(0);
  }, [banners, position]);

  const goTo = (target: number, duration: number, auto: boolean) => {
    if (animating.current) return;
    animating.current = true;
    if (auto && !reduced) {
      Animated.sequence([
        Animated.timing(edge, { toValue: 1, duration: duration / 2, easing: EASE.out, useNativeDriver: true }),
        Animated.timing(edge, { toValue: 0, duration: duration / 2, easing: EASE.in, useNativeDriver: true }),
      ]).start();
    }
    Animated.timing(position, { toValue: target, duration, easing: EASE.inOut, useNativeDriver: true }).start(() => {
      animating.current = false;
      settled.current = target;
      setBase(target);
    });
  };

  const playing = count > 1 && focused && appActive && !dragging && !reduced;

  // The single auto-advance timer: re-armed only when the settled slide or `playing` changes.
  useEffect(() => {
    if (!playing) return;
    const timer = setTimeout(() => goTo(settled.current + 1, DURATION.hero, true), AUTO_ADVANCE_MS);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [playing, base]);

  const pan = useMemo(
    () =>
      PanResponder.create({
        onMoveShouldSetPanResponder: (_, g) => count > 1 && Math.abs(g.dx) > 8 && Math.abs(g.dx) > Math.abs(g.dy) * 1.2,
        onPanResponderGrant: () => {
          setDragging(true);
          position.stopAnimation();
          animating.current = false;
        },
        onPanResponderMove: (_, g) => position.setValue(settled.current - g.dx / width),
        onPanResponderRelease: (_, g) => {
          const projected = settled.current - (g.dx + g.vx * 180) / width;
          const target = Math.max(settled.current - 1, Math.min(settled.current + 1, Math.round(projected)));
          setDragging(false);
          goTo(target, SWIPE_MS, false);
        },
        onPanResponderTerminate: () => {
          setDragging(false);
          goTo(settled.current, SWIPE_MS, false);
        },
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [count, width],
  );

  if (banners === null) {
    return <Skeleton width={width} height={height} radius={RADIUS.xl} />;
  }

  if (count === 0) {
    return (
      <View style={[styles.frame, styles.placeholder, { width, height }]} accessibilityLabel={label}>
        <BrandLogo width={Math.round(width * 0.24)} />
        <Text style={styles.placeholderText}>{emptyText}</Text>
      </View>
    );
  }

  const active = mod(base, count);
  const slides = count > 1 ? [base - 1, base, base + 1] : [0];

  return (
    <View style={[styles.frame, { width, height }]} {...pan.panHandlers} accessibilityLabel={`${label}, ${active + 1} of ${count}`}>
      {slides.map((slot) => {
        const banner = banners[mod(slot, count)];
        const translateX = count > 1 ? position.interpolate({ inputRange: [slot - 1, slot, slot + 1], outputRange: [width, 0, -width] }) : 0;
        const opacity = count > 1 ? position.interpolate({ inputRange: [slot - 1, slot, slot + 1], outputRange: [0.75, 1, 0.55], extrapolate: 'clamp' }) : 1;

        return (
          <Animated.View key={`slot-${slot}`} style={[styles.slide, { width, height, opacity, transform: [{ translateX }] }]}>
            <Slide banner={banner} width={width} height={height} onOpenLink={onOpenLink} />
          </Animated.View>
        );
      })}

      {!reduced && count > 1 && (
        <Animated.View pointerEvents="none" style={[styles.edges, { opacity: edge }]}>
          <Svg width={width} height={height}>
            <Defs>
              <LinearGradient id="mm-hero-left" x1="0" y1="0" x2="1" y2="0">
                <Stop offset="0" stopColor={colors.bg} stopOpacity={0.8} />
                <Stop offset="1" stopColor={colors.bg} stopOpacity={0} />
              </LinearGradient>
              <LinearGradient id="mm-hero-right" x1="1" y1="0" x2="0" y2="0">
                <Stop offset="0" stopColor={colors.bg} stopOpacity={0.8} />
                <Stop offset="1" stopColor={colors.bg} stopOpacity={0} />
              </LinearGradient>
            </Defs>
            <Rect x="0" y="0" width={width * 0.18} height={height} fill="url(#mm-hero-left)" />
            <Rect x={width * 0.82} y="0" width={width * 0.18} height={height} fill="url(#mm-hero-right)" />
          </Svg>
        </Animated.View>
      )}

      {count > 1 && (
        <View style={styles.dotsWrap} pointerEvents="none">
          <View style={styles.dots}>
            {banners.map((banner, dot) => (
              <Dot key={banner.id} active={dot === active} />
            ))}
          </View>
        </View>
      )}
    </View>
  );
}

function Dot({ active }: { active: boolean }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const value = useRef(new Animated.Value(active ? 1 : 0)).current;

  useEffect(() => {
    Animated.timing(value, { toValue: active ? 1 : 0, duration: DURATION.card, easing: EASE.inOut, useNativeDriver: false }).start();
  }, [active, value]);

  return (
    <Animated.View
      style={[
        styles.dot,
        {
          width: value.interpolate({ inputRange: [0, 1], outputRange: [6, 16] }),
          backgroundColor: value.interpolate({ inputRange: [0, 1], outputRange: [colors.fg3, colors.fg] }),
        },
      ]}
    />
  );
}

function Slide({ banner, width, height, onOpenLink }: { banner: HeroBanner; width: number; height: number; onOpenLink?: (banner: HeroBanner) => void }) {
  const styles = useStyles();
  const { colors } = useTheme();
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>(banner.image_url ? 'loading' : 'failed');
  const fade = useRef(new Animated.Value(0)).current;
  const hasLink = Boolean(banner.link_url && onOpenLink);

  const picture = (
    <View style={{ width, height }}>
      {status !== 'failed' && banner.image_url ? (
        <Animated.Image
          source={{ uri: banner.image_url }}
          style={[styles.fill, { opacity: fade }]}
          resizeMode="cover"
          onLoad={() => {
            setStatus('ready');
            Animated.timing(fade, { toValue: 1, duration: DURATION.card, easing: EASE.out, useNativeDriver: true }).start();
          }}
          onError={() => setStatus('failed')}
          accessibilityLabel={banner.title ?? 'Banner'}
        />
      ) : (
        <View style={[styles.fill, styles.failed]}>
          <Icon name="image" size={26} color={colors.fg3} />
          <Text style={styles.failedText}>Banner unavailable</Text>
        </View>
      )}
      {status === 'loading' && (
        <View style={[styles.fill, styles.center]}>
          <ActivityIndicator color={colors.brandText} />
        </View>
      )}
    </View>
  );

  if (!hasLink) return picture;

  return (
    <Pressable onPress={() => onOpenLink?.(banner)} accessibilityRole="link" accessibilityLabel={`${banner.title ?? 'Banner'}. Opens a link`}>
      {picture}
    </Pressable>
  );
}

const FILL = { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 } as const;


const useStyles = makeStyles(({ colors: c }) => ({
  frame: {
    borderRadius: RADIUS.xl,
    overflow: 'hidden',
    backgroundColor: c.surface,
    borderWidth: 1,
    borderColor: c.border,
  },
  slide: { position: 'absolute', top: 0, left: 0 },
  fill: { ...FILL },
  center: { alignItems: 'center', justifyContent: 'center' },
  edges: { ...FILL },
  placeholder: { alignItems: 'center', justifyContent: 'center', gap: SPACE.xs },
  placeholderText: { ...TYPE.bodyStrong, color: c.fg2 },
  failed: { alignItems: 'center', justifyContent: 'center', gap: SPACE.xxs + 2, backgroundColor: c.surface2 },
  failedText: { ...TYPE.meta, color: c.fg3 },
  dotsWrap: { position: 'absolute', left: 0, right: 0, bottom: SPACE.xs, alignItems: 'center' },
  dots: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: RADIUS.pill,
    backgroundColor: glassScrim(c.bg, 'chip'),
  },
  dot: { height: 6, borderRadius: 3 },
}));
