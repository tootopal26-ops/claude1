import { useState, type Ref } from 'react';
import { Pressable, Text, TextInput, View, type TextInputInstance, type TextInputProps } from 'react-native';

import { Icon } from '../theme/icons';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../theme/tokens';

type TextFieldProps = TextInputProps & {
  label?: string;
  /** Marks the field invalid without a message (the message is shown elsewhere). */
  error?: boolean;
  /** The field's own error, shown under it and announced. */
  errorText?: string;
  /** Short guidance under the field (hidden while an error shows). */
  hint?: string;
  /** A password field: hidden by default with a show/hide toggle. */
  password?: boolean;
  ref?: Ref<TextInputInstance>;
};

/**
 * A labelled text input: label above, readable value and placeholder, a visible focus
 * state (brand border), an inline error under the field, and — for passwords — a 44-dp
 * show/hide toggle. `label` is also the accessibility label.
 */
export function TextField({ label, error = false, errorText, hint, password = false, style, onFocus, onBlur, ref, ...rest }: TextFieldProps) {
  const styles = useStyles();
  const { colors } = useTheme();
  const [focused, setFocused] = useState(false);
  const [revealed, setRevealed] = useState(false);
  const invalid = error || Boolean(errorText);

  return (
    <View style={styles.group}>
      {label ? <Text style={styles.label}>{label}</Text> : null}
      <View style={styles.inputWrap}>
        <TextInput
          ref={ref}
          accessibilityLabel={label ?? rest.placeholder}
          accessibilityHint={errorText ?? hint}
          placeholderTextColor={colors.fg3}
          selectionColor={colors.brand}
          cursorColor={colors.brandText}
          secureTextEntry={password && !revealed}
          autoCapitalize={password ? 'none' : rest.autoCapitalize}
          autoCorrect={password ? false : rest.autoCorrect}
          {...rest}
          onFocus={(event) => {
            setFocused(true);
            onFocus?.(event);
          }}
          onBlur={(event) => {
            setFocused(false);
            onBlur?.(event);
          }}
          style={[styles.input, password && styles.inputWithToggle, focused && styles.inputFocused, invalid && styles.inputError, style]}
        />
        {password ? (
          <Pressable
            onPress={() => setRevealed((value) => !value)}
            accessibilityRole="button"
            accessibilityLabel={revealed ? 'Hide password' : 'Show password'}
            style={styles.toggle}
            hitSlop={4}
          >
            <Icon name={revealed ? 'eye-off' : 'eye'} size={20} color={colors.fg2} />
          </Pressable>
        ) : null}
      </View>
      {errorText ? (
        <Text style={styles.errorText} accessibilityLiveRegion="polite">
          {errorText}
        </Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  group: { gap: 6 },
  label: { ...TYPE.meta, fontWeight: '600', color: c.fg2 },
  inputWrap: { justifyContent: 'center' },
  input: {
    minHeight: SIZE.button,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.borderStrong,
    borderRadius: RADIUS.md,
    paddingHorizontal: SPACE.md,
    paddingVertical: SPACE.sm,
    color: c.fg,
    fontSize: 15,
  },
  inputWithToggle: { paddingRight: SIZE.touch + SPACE.xxs },
  inputFocused: { borderColor: c.brand },
  inputError: { borderColor: s.danger },
  toggle: { position: 'absolute', right: 2, width: SIZE.touch, height: SIZE.touch, alignItems: 'center', justifyContent: 'center' },
  errorText: { ...TYPE.meta, color: s.danger, fontWeight: '600' },
  hint: { ...TYPE.meta, color: c.fg3 },
}));
