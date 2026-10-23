import { ActivityIndicator, Pressable, Text, type PressableProps, type StyleProp, type ViewStyle } from 'react-native';

import { makeStyles, useTheme } from '../theme/ThemeContext';

type ButtonProps = Omit<PressableProps, 'style' | 'children'> & {
  label: string;
  variant?: 'primary' | 'secondary';
  loading?: boolean;
  style?: StyleProp<ViewStyle>;
};

/**
 * The one button: brand-filled for the primary action on a screen, a quiet
 * outlined surface for anything secondary. Extra Pressable props pass
 * through, so it also works as the child of expo-router's <Link asChild>.
 */
export function Button({ label, variant = 'primary', loading = false, disabled, style, ...rest }: ButtonProps) {
  const styles = useStyles();
  const { colors } = useTheme();
  const isDisabled = Boolean(disabled) || loading;
  const primary = variant === 'primary';

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      {...rest}
      disabled={isDisabled}
      style={({ pressed }) => [
        styles.base,
        primary ? styles.primary : styles.secondary,
        pressed && (primary ? styles.primaryPressed : styles.secondaryPressed),
        isDisabled && styles.disabled,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={primary ? colors.onBrand : colors.brandText} />
      ) : (
        <Text style={[styles.label, primary ? styles.primaryLabel : styles.secondaryLabel]}>{label}</Text>
      )}
    </Pressable>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  base: {
    minHeight: 48,
    borderRadius: 12,
    paddingHorizontal: 16,
    paddingVertical: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primary: { backgroundColor: c.brand },
  primaryPressed: { backgroundColor: c.brandPressed },
  secondary: { backgroundColor: c.surface2, borderWidth: 1, borderColor: c.borderStrong },
  secondaryPressed: { backgroundColor: c.surface3 },
  disabled: { opacity: 0.5 },
  label: { fontWeight: '600', fontSize: 16 },
  primaryLabel: { color: c.onBrand },
  secondaryLabel: { color: c.fg },
}));
