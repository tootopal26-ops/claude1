import { Image, View, type ImageSourcePropType, type StyleProp, type ViewStyle } from 'react-native';

/**
 * The supplied colourful icon artwork (mobile/assets/icons, /status and /nav — see
 * mobile/assets/ICONS.md for where each file came from). These are the main artwork:
 * screens use them instead of the outline glyphs in icons.tsx wherever a supplied
 * picture exists.
 *
 * `aspect` is each file's real width / height, so an icon can be fitted inside a box
 * without being stretched, squashed or cropped.
 */
export const ARTWORK = {
  profile: { source: require('../../assets/icons/profile.png'), aspect: 192 / 188 },
  wallet: { source: require('../../assets/icons/wallet.png'), aspect: 188 / 192 },
  bell: { source: require('../../assets/icons/bell.png'), aspect: 152 / 155 },
  redeem: { source: require('../../assets/icons/redeem.png'), aspect: 192 / 166 },
  settings: { source: require('../../assets/icons/settings.png'), aspect: 192 / 190 },
  terms: { source: require('../../assets/icons/terms.png'), aspect: 192 / 188 },
  support: { source: require('../../assets/icons/support.png'), aspect: 179 / 192 },
  adsEarning: { source: require('../../assets/icons/ads-earning.png'), aspect: 192 / 161 },
  matches: { source: require('../../assets/icons/matches.png'), aspect: 187 / 192 },
  logout: { source: require('../../assets/icons/logout.png'), aspect: 192 / 187 },
  deposit: { source: require('../../assets/icons/deposit.png'), aspect: 228 / 256 },
  withdraw: { source: require('../../assets/icons/withdraw.png'), aspect: 254 / 256 },
  calendar: { source: require('../../assets/icons/calendar.png'), aspect: 165 / 144 },
  medal1: { source: require('../../assets/icons/medal-1.png'), aspect: 256 / 232 },
  medal2: { source: require('../../assets/icons/medal-2.png'), aspect: 256 / 232 },
  medal3: { source: require('../../assets/icons/medal-3.png'), aspect: 256 / 236 },
  modeSolo: { source: require('../../assets/icons/mode-solo.png'), aspect: 148 / 133 },
  modeDuo: { source: require('../../assets/icons/mode-duo.png'), aspect: 171 / 141 },
  modeSquad: { source: require('../../assets/icons/mode-squad.png'), aspect: 182 / 147 },
  prizePool: { source: require('../../assets/icons/prize-pool.png'), aspect: 238 / 256 },
  perKill: { source: require('../../assets/icons/per-kill.png'), aspect: 237 / 256 },
  entryFee: { source: require('../../assets/icons/entry-fee.png'), aspect: 229 / 256 },
  statusOngoing: { source: require('../../assets/status/ongoing.png'), aspect: 320 / 206 },
  statusUpcoming: { source: require('../../assets/status/upcoming.png'), aspect: 320 / 237 },
  statusCompleted: { source: require('../../assets/status/completed.png'), aspect: 320 / 238 },
  navLeaderboard: { source: require('../../assets/nav/leaderboard.png'), aspect: 144 / 138 },
  navHome: { source: require('../../assets/nav/home.png'), aspect: 144 / 132 },
  navProfile: { source: require('../../assets/nav/profile.png'), aspect: 127 / 144 },
} satisfies Record<string, { source: ImageSourcePropType; aspect: number }>;

export type ArtworkName = keyof typeof ARTWORK;

/**
 * One box size per use. Icons in the same group share a box, so their visual weight lines
 * up; the artwork is fitted inside the box by its own aspect ratio (wide art gets a
 * shorter box height, tall art a narrower width) and never cropped.
 */
export const ARTWORK_SIZE = {
  /** Profile menu rows. */
  menu: 32,
  /** Inside the Deposit / Withdraw buttons. */
  walletAction: 26,
  /** Leaderboard medals for ranks 2 and 3 (podium and list). */
  medal: 34,
  /** The first-place medal, a little more prominent. */
  medalFirst: 40,
  /** A date or mode marker next to text on a card. */
  inline: 18,
  /** Prize pool / Kill / Entry fee badges on a tournament card (their words are part of the art). */
  metric: 60,
  /** The same badges on the tournament details screen. */
  metricDetail: 72,
  /** The Home header bell. */
  headerBell: 26,
  /** Bottom navigation. */
  nav: 30,
} as const;

/** Width and height that fit `name` inside a `box`-sized square without distortion. */
export function fitArtwork(name: ArtworkName, box: number): { width: number; height: number } {
  const { aspect } = ARTWORK[name];
  return aspect >= 1 ? { width: box, height: Math.round(box / aspect) } : { width: Math.round(box * aspect), height: box };
}

type ArtworkProps = {
  name: ArtworkName;
  /** The square box the artwork is fitted into (see ARTWORK_SIZE). */
  size: number;
  style?: StyleProp<ViewStyle>;
  /** Decorative by default: the label next to it already says what it is. */
  accessibilityLabel?: string;
};

/** A supplied artwork icon, centred in a `size` square, at its own aspect ratio. */
export function Artwork({ name, size, style, accessibilityLabel }: ArtworkProps) {
  const fitted = fitArtwork(name, size);

  return (
    <View
      style={[{ width: size, height: size, alignItems: 'center', justifyContent: 'center' }, style]}
      accessible={accessibilityLabel !== undefined}
      accessibilityRole={accessibilityLabel !== undefined ? 'image' : undefined}
      accessibilityLabel={accessibilityLabel}
      importantForAccessibility={accessibilityLabel !== undefined ? 'yes' : 'no-hide-descendants'}
      accessibilityElementsHidden={accessibilityLabel === undefined}
    >
      <Image source={ARTWORK[name].source} style={fitted} resizeMode="contain" />
    </View>
  );
}
