import { Image, type ImageStyle, type StyleProp } from 'react-native';

/**
 * The Mini Max wordmark, cut out of mobile/assets/logo.png (the
 * user-supplied brand artwork) onto a transparent background. Renders at
 * its natural 1.2:1 aspect ratio: pass `width` and the height follows.
 *
 * The height is set explicitly on purpose. A require()d image starts from its
 * intrinsic size (1065×861), and an explicit height beats `aspectRatio`, so
 * a width alone left a box tall enough to push the whole login form off the
 * first screen.
 */
const LOGO_ASPECT = 1065 / 861;
const LOGO_IMAGE = require('../../assets/logo.png');

type BrandLogoProps = {
  width?: number;
  style?: StyleProp<ImageStyle>;
};

export function BrandLogo({ width = 220, style }: BrandLogoProps) {
  return (
    <Image
      source={LOGO_IMAGE}
      style={[{ width, height: Math.round(width / LOGO_ASPECT) }, style]}
      resizeMode="contain"
      accessibilityLabel="Mini Max"
    />
  );
}
