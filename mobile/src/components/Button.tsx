import { ActivityIndicator, Text, View, type PressableProps, type StyleProp, type ViewStyle } from 'react-native';

import { Artwork, ARTWORK_SIZE, type ArtworkName } from '../theme/artwork';
import { PRESS_SCALE } from '../theme/motion';
import { Icon, type IconName } from '../theme/icons';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../theme/tokens';
import { PressableScale } from './interaction/PressableScale';

type ButtonProps = Omit<PressableProps, 'style' | 'children'> & {
  label: string;
  /**
   * primary: the main action (purple). secondary: a quiet dark surface. text: an inline
   * link-like action. danger: destructive actions, the red Redeem action and Withdraw.
   * success: the green Deposit / Add coins action.
   */
  variant?: 'primary' | 'secondary' | 'text' | 'danger' | 'success';
  size?: 'md' | 'sm';
  icon?: IconName;
  /** Supplied colourful artwork shown instead of `icon` (for example the wallet actions). */
  artwork?: ArtworkName;
  loading?: boolean;
  /** Layout only (flex, width, margins): it sizes the touch area and the button fills it. */
  style?: StyleProp<ViewStyle>;
};

/**
 * The one button. Heights, radius, type and states are the same everywhere: an immediate
 * pressed response, a spinner (and no second tap) while `loading`, and a dimmed, inert
 * look when disabled.
 */
export function Button({ label, variant = 'primary', size = 'md', icon, artwork, loading = false, disabled, style, accessibilityLabel, ...rest }: ButtonProps) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const isDisabled = Boolean(disabled) || loading;
  const foreground =
    variant === 'primary'
      ? colors.onBrand
      : variant === 'danger'
        ? semanticColors.onDanger
        : variant === 'success'
          ? semanticColors.onSuccess
          : variant === 'text'
            ? colors.brandText
            : colors.fg;
  const solid = variant === 'primary' || variant === 'danger' || variant === 'success';

  return (
    <PressableScale
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      aria-disabled={isDisabled}
      aria-busy={loading}
      {...rest}
      disabled={isDisabled}
      scaleTo={PRESS_SCALE.button}
      layoutStyle={style}
      style={[styles.base, size === 'sm' && styles.small, styles[variant], isDisabled && styles.disabled]}
      pressedStyle={styles[`${variant}Pressed`]}
    >
      {loading ? (
        <ActivityIndicator color={foreground} />
      ) : (
        <View style={styles.content}>
          {/* Supplied artwork when given; otherwise white glyphs on solid buttons and the filled, coloured icon on dark ones. */}
          {artwork ? (
            <Artwork name={artwork} size={size === 'sm' ? 20 : ARTWORK_SIZE.walletAction} />
          ) : icon ? (
            <Icon name={icon} size={size === 'sm' ? 16 : 18} color={solid ? foreground : undefined} accent={foreground} />
          ) : null}
          <Text style={[styles.label, size === 'sm' && styles.labelSmall, { color: foreground }]} numberOfLines={1}>
            {label}
          </Text>
        </View>
      )}
    </PressableScale>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  base: {
    minHeight: SIZE.button,
    borderRadius: RADIUS.md,
    paddingHorizontal: SPACE.md,
    alignItems: 'center',
    justifyContent: 'center',
  },
  small: { minHeight: SIZE.buttonSm, paddingHorizontal: SPACE.sm },
  content: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: SPACE.xs },
  label: { ...TYPE.bodyStrong, fontSize: 15 },
  labelSmall: { fontSize: 13 },
  primary: { backgroundColor: c.brand },
  primaryPressed: { backgroundColor: c.brandPressed },
  secondary: { backgroundColor: c.surface2, borderWidth: 1, borderColor: c.borderStrong },
  secondaryPressed: { backgroundColor: c.surface3 },
  text: { backgroundColor: 'transparent', paddingHorizontal: SPACE.xs },
  textPressed: { backgroundColor: c.surface2 },
  danger: { backgroundColor: s.dangerSolid, borderWidth: 1, borderColor: 'rgba(255,255,255,0.14)' },
  dangerPressed: { opacity: 0.9 },
  success: { backgroundColor: s.successSolid, borderWidth: 1, borderColor: 'rgba(255,255,255,0.14)' },
  successPressed: { opacity: 0.9 },
  disabled: { opacity: 0.45 },
}));
