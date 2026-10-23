import { useCallback, useEffect, useRef, useState } from 'react';
import {
  AccessibilityInfo,
  ActivityIndicator,
  AppState,
  FlatList,
  Image,
  Text,
  useWindowDimensions,
  View,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
} from 'react-native';

import { fetchLeaderboardBanners } from '../api/leaderboards';
import { Icon } from '../theme/icons';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import type { LeaderboardBanner } from '../types';
import { BrandLogo } from './BrandLogo';

const SIDE_PADDING = 16;
const AUTO_SLIDE_MS = 5000;
const RESUME_AFTER_TOUCH_MS = 8000;

type Props = {
  /** Change to refetch (pull-to-refresh). */
  refreshKey?: number;
};

/**
 * The swipeable 16:9 banner strip at the top of the leaderboard. Pictures are
 * stored at exactly 16:9, so the frame keeps that ratio at any width and the
 * image is never stretched. With one banner there are no indicators; with
 * several it auto-advances slowly — but never while the user is touching it,
 * when the app is in the background, or when the system asks for reduced
 * motion or a screen reader is on — and swiping always works.
 */
export function BannerCarousel({ refreshKey = 0 }: Props) {
  const { colors } = useTheme();
  const styles = useStyles();
  const { width: screenWidth } = useWindowDimensions();
  const width = screenWidth - SIDE_PADDING * 2;
  const [banners, setBanners] = useState<LeaderboardBanner[] | null>(null);
  const [index, setIndex] = useState(0);
  const listRef = useRef<FlatList<LeaderboardBanner>>(null);
  const pausedUntil = useRef(0);
  const [autoSlideAllowed, setAutoSlideAllowed] = useState(false);

  useEffect(() => {
    let cancelled = false;
    fetchLeaderboardBanners()
      .then((response) => {
        if (!cancelled) setBanners(response.data.filter((banner) => banner.image_url));
      })
      .catch(() => {
        // Banners are decorative: a failed fetch shows the branded placeholder, never an error.
        if (!cancelled) setBanners([]);
      });

    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  useEffect(() => {
    let mounted = true;
    Promise.all([AccessibilityInfo.isReduceMotionEnabled(), AccessibilityInfo.isScreenReaderEnabled()]).then(
      ([reduceMotion, screenReader]) => {
        if (mounted) setAutoSlideAllowed(!reduceMotion && !screenReader);
      },
    );

    return () => {
      mounted = false;
    };
  }, []);

  const count = banners?.length ?? 0;

  useEffect(() => {
    if (!autoSlideAllowed || count < 2) return;

    const timer = setInterval(() => {
      if (AppState.currentState !== 'active' || Date.now() < pausedUntil.current) return;
      const next = (index + 1) % count;
      listRef.current?.scrollToIndex({ index: next, animated: true });
      setIndex(next);
    }, AUTO_SLIDE_MS);

    return () => clearInterval(timer);
  }, [autoSlideAllowed, count, index]);

  const onMomentumEnd = useCallback(
    (event: NativeSyntheticEvent<NativeScrollEvent>) => {
      setIndex(Math.round(event.nativeEvent.contentOffset.x / width));
    },
    [width],
  );

  const pause = useCallback(() => {
    pausedUntil.current = Date.now() + RESUME_AFTER_TOUCH_MS;
  }, []);

  if (banners === null) {
    return (
      <View style={[styles.frame, { width }]} accessibilityLabel="Loading banners" accessibilityRole="progressbar">
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  if (count === 0) {
    return (
      <View style={[styles.frame, styles.placeholder, { width }]} accessibilityLabel="Leaderboard">
        <BrandLogo width={72} />
        <Text style={styles.placeholderText}>Climb the leaderboard</Text>
      </View>
    );
  }

  return (
    <View style={{ width }} accessibilityLabel={`Leaderboard banners, ${index + 1} of ${count}`}>
      <FlatList
        ref={listRef}
        data={banners}
        keyExtractor={(item) => String(item.id)}
        horizontal
        pagingEnabled
        snapToInterval={width}
        decelerationRate="fast"
        showsHorizontalScrollIndicator={false}
        getItemLayout={(_, itemIndex) => ({ length: width, offset: width * itemIndex, index: itemIndex })}
        onScrollBeginDrag={pause}
        onMomentumScrollEnd={onMomentumEnd}
        renderItem={({ item }) => <BannerSlide banner={item} width={width} />}
      />
      {count > 1 && (
        <View style={styles.dotsWrap} pointerEvents="none">
          <View style={styles.dots}>
            {banners.map((banner, dotIndex) => (
              <View key={banner.id} style={[styles.dot, dotIndex === index && styles.dotActive]} />
            ))}
          </View>
        </View>
      )}
    </View>
  );
}

function BannerSlide({ banner, width }: { banner: LeaderboardBanner; width: number }) {
  const { colors } = useTheme();
  const styles = useStyles();
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>('loading');

  return (
    <View style={[styles.frame, { width }]}>
      {status !== 'failed' && banner.image_url ? (
        <Image
          source={{ uri: banner.image_url }}
          style={styles.image}
          resizeMode="cover"
          onLoad={() => setStatus('ready')}
          onError={() => setStatus('failed')}
          accessibilityLabel={banner.title ?? 'Leaderboard banner'}
          accessibilityIgnoresInvertColors
        />
      ) : (
        <View style={styles.failed}>
          <Icon name="image" size={26} color={colors.fg3} />
          <Text style={styles.failedText}>Banner unavailable</Text>
        </View>
      )}
      {status === 'loading' && (
        <View style={styles.loadingOverlay}>
          <ActivityIndicator color={colors.brandText} />
        </View>
      )}
    </View>
  );
}

const FILL = { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 } as const;

const useStyles = makeStyles(({ colors: c }) => ({
  // Glass frame: hairline border and a translucent surface behind the picture, so the
  // banner reads as one panel with the rest of the screen even while it loads.
  frame: {
    aspectRatio: 16 / 9,
    borderRadius: 18,
    overflow: 'hidden',
    backgroundColor: `${c.surface}e6`,
    borderWidth: 1,
    borderColor: c.borderStrong,
    alignItems: 'center',
    justifyContent: 'center',
  },
  image: { ...FILL },
  placeholder: { gap: 10 },
  placeholderText: { color: c.fg2, fontSize: 13, fontWeight: '600' },
  failed: { ...FILL, alignItems: 'center', justifyContent: 'center', gap: 6, backgroundColor: c.surface2 },
  failedText: { color: c.fg3, fontSize: 12 },
  loadingOverlay: { ...FILL, alignItems: 'center', justifyContent: 'center' },
  dotsWrap: { position: 'absolute', left: 0, right: 0, bottom: 10, alignItems: 'center' },
  dots: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 999,
    backgroundColor: `${c.bg}b8`,
    borderWidth: 1,
    borderColor: c.border,
  },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: c.fg3 },
  dotActive: { width: 16, backgroundColor: c.brandText },
}));
