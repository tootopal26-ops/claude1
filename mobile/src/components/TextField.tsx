import { useState } from 'react';
import { Text, TextInput, View, type TextInputProps } from 'react-native';

import { makeStyles, useTheme } from '../theme/ThemeContext';

type TextFieldProps = TextInputProps & {
  label?: string;
  error?: boolean;
};

/**
 * A labelled text input with readable value/placeholder colors and a visible
 * focus state (the border switches to the brand color). `label` is shown
 * above the field and is also the accessibility label.
 */
export function TextField({ label, error = false, style, onFocus, onBlur, ...rest }: TextFieldProps) {
  const styles = useStyles();
  const { colors } = useTheme();
  const [focused, setFocused] = useState(false);

  return (
    <View style={styles.group}>
      {label ? <Text style={styles.label}>{label}</Text> : null}
      <TextInput
        accessibilityLabel={label ?? rest.placeholder}
        placeholderTextColor={colors.fg3}
        selectionColor={colors.brand}
        cursorColor={colors.brandText}
        {...rest}
        onFocus={(event) => {
          setFocused(true);
          onFocus?.(event);
        }}
        onBlur={(event) => {
          setFocused(false);
          onBlur?.(event);
        }}
        style={[styles.input, focused && styles.inputFocused, error && styles.inputError, style]}
      />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  group: { gap: 6 },
  label: { color: c.fg2, fontSize: 12, fontWeight: '600' },
  input: {
    minHeight: 48,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.borderStrong,
    borderRadius: 12,
    paddingHorizontal: 16,
    paddingVertical: 12,
    color: c.fg,
    fontSize: 15,
  },
  inputFocused: { borderColor: c.brand },
  inputError: { borderColor: s.danger },
}));
