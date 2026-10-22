import { Image, type ImageStyle, type StyleProp } from 'react-native';

/**
 * The one and only place that renders the coin graphic. Player-facing
 * code must always go through this component rather than an emoji, a
 * raw rupee symbol, or a hand-rolled coin SVG — see
 * scripts/check-no-raw-currency.js, which enforces that across app/ and
 * src/.
 *
 * mobile/assets/coin.png is the user-supplied coin artwork.
 */
const COIN_IMAGE = require('../../assets/coin.png');

type CoinAssetProps = {
  size?: number;
  style?: StyleProp<ImageStyle>;
};

export function CoinAsset({ size = 20, style }: CoinAssetProps) {
  return (
    <Image
      source={COIN_IMAGE}
      style={[{ width: size, height: size }, style]}
      resizeMode="contain"
      accessibilityLabel="coins"
    />
  );
}
