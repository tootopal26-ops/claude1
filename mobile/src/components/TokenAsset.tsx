import { Image, type ImageStyle, type StyleProp } from 'react-native';

/**
 * The one and only place that renders the spin-token graphic — the same convention as
 * CoinAsset (see its own comment): always this component, never an emoji or a hand-rolled
 * icon, so the artwork can never drift across the Spin screen, the token balance pill and
 * the exchange cards.
 *
 * mobile/assets/token.png is the user-supplied token artwork (a purple/gold crown coin),
 * distinct from assets/coin.png — tokens and coins are separate currencies with separate
 * server-side balances (TokenService vs WalletService) and must never look identical.
 */
const TOKEN_IMAGE = require('../../assets/token.png');

type TokenAssetProps = {
  size?: number;
  style?: StyleProp<ImageStyle>;
};

export function TokenAsset({ size = 20, style }: TokenAssetProps) {
  return <Image source={TOKEN_IMAGE} style={[{ width: size, height: size }, style]} resizeMode="contain" accessibilityLabel="tokens" />;
}
