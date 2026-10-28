import { useState } from 'react';
import { Text, View, type StyleProp, type ViewStyle } from 'react-native';

import { glassPanel } from '../theme/glass';
import { makeStyles } from '../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../theme/tokens';
import { useAds } from './AdsContext';
import { BannerAdView, NativeAdView } from './sdk';
import type { AdPlacementKey } from './types';

type Props = {
  placement: AdPlacementKey;
  /** Native ads only: the template size. */
  size?: 'small' | 'medium';
  style?: StyleProp<ViewStyle>;
};

/**
 * An optional banner or native ad slot. It renders nothing unless Admin enabled the
 * placement and the ad SDK is running, and it stays collapsed until an ad actually fills it
 * — no empty boxes, no layout jump on a no-fill. A filled slot is framed and labelled "Ad",
 * so it can never be mistaken for a tournament, a player row or a game card.
 */
export function AdSlot({ placement: key, size = 'small', style }: Props) {
  const styles = useStyles();
  const { placement, sdkReady, track } = useAds();
  const [filled, setFilled] = useState(false);
  const item = placement(key);

  if (!sdkReady || !item || (item.format !== 'banner' && item.format !== 'native')) {
    return null;
  }

  return (
    <View style={[styles.frame, !filled && styles.collapsed, style]} accessibilityLabel="Advertisement">
      {filled && (
        <View style={styles.labelRow}>
          <Text style={styles.label}>Ad</Text>
        </View>
      )}
      {item.format === 'banner' ? (
        <BannerAdView adUnitId={item.ad_unit_id} placement={key} onEvent={track} onFilled={() => setFilled(true)} />
      ) : (
        <NativeAdView placement={key} size={size} onEvent={track} onFilled={() => setFilled(true)} />
      )}
    </View>
  );
}

/** Where feed ads go: after every `every` real rows, never before the first or after the last. */
export function feedAdPositions(count: number, every: number | null | undefined): Set<number> {
  const positions = new Set<number>();
  if (!every || every < 3) return positions;
  for (let index = every - 1; index < count - 1; index += every) positions.add(index);
  return positions;
}

const useStyles = makeStyles(({ colors: c }) => ({
  frame: { ...glassPanel(c, 'surface2'), borderRadius: RADIUS.lg, padding: SPACE.xs, gap: SPACE.xxs, overflow: 'hidden' },
  // Kept mounted (so the ad can load) but takes no space until it fills.
  collapsed: { height: 0, padding: 0, borderWidth: 0, opacity: 0 },
  labelRow: { flexDirection: 'row' },
  label: { ...TYPE.label, color: c.fg2, backgroundColor: c.surface3, borderRadius: RADIUS.sm, paddingHorizontal: SPACE.xs, paddingVertical: 2, overflow: 'hidden' },
}));
