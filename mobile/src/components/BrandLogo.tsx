import { Image, type ImageStyle, type StyleProp } from 'react-native';

/**
 * The Mini Max wordmark, cut out of mobile/assets/logo.png (the
 * user-supplied brand artwork) onto a transparent background. Renders at
 * its natural ~1.2:1 aspect ratio — pass `width` (or a style width) and
 * let `resizeMode="contain"` derive the height.
 */
const LOGO_IMAGE = require('../../assets/logo.png');

type BrandLogoProps = {
  width?: number;
  style?: StyleProp<ImageStyle>;
};

export function BrandLogo({ width = 220, style }: BrandLogoProps) {
  return (
    <Image
      source={LOGO_IMAGE}
      style={[{ width, aspectRatio: 1200 / 1000 }, style]}
      resizeMode="contain"
      accessibilityLabel="Mini Max"
    />
  );
}
