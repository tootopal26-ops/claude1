import { useState } from 'react';
import { Text, TextInput, View, type TextInputProps } from 'react-native';

import { makeStyles, useTheme } from '../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../theme/tokens';
import { COUNTRY_CODE, normalizeMobileInput } from '../utils/mobileNumber';

type MobileNumberFieldProps = {
  label?: string;
  /** The 10 digits typed after the country code ('' while empty). */
  value: string;
  /** Called with the cleaned digits only — never with the +91. */
  onChange: (digits: string) => void;
  errorText?: string;
  hint?: string;
  returnKeyType?: TextInputProps['returnKeyType'];
  onSubmitEditing?: TextInputProps['onSubmitEditing'];
};

/**
 * The mobile number input: a fixed, non-editable "+91" inside the field's box, then the
 * 10 digits. It looks and behaves like `TextField` (label above, brand border on focus,
 * inline error), but only digits are accepted and the value is capped at 10.
 */
export function MobileNumberField({ label = 'Mobile number', value, onChange, errorText, hint, returnKeyType, onSubmitEditing }: MobileNumberFieldProps) {
  const styles = useStyles();
  const { colors } = useTheme();
  const [focused, setFocused] = useState(false);
  const invalid = Boolean(errorText);

  return (
    <View style={styles.group}>
      <Text style={styles.label}>{label}</Text>
      <View style={[styles.box, focused && styles.boxFocused, invalid && styles.boxError]}>
        <View style={styles.prefix} accessible={false} importantForAccessibility="no-hide-descendants">
          <Text style={styles.prefixText}>{COUNTRY_CODE}</Text>
        </View>
        <TextInput
          style={styles.input}
          value={value}
          // Cleaned here rather than with `maxLength`: a native cap would cut a pasted or
          // autofilled "+91 8273093895" down to "+91827309" before it could be tidied.
          onChangeText={(text) => onChange(normalizeMobileInput(text))}
          placeholder="10-digit mobile number"
          placeholderTextColor={colors.fg3}
          selectionColor={colors.brand}
          cursorColor={colors.brandText}
          keyboardType="number-pad"
          autoComplete="tel-national"
          textContentType="telephoneNumber"
          autoCorrect={false}
          returnKeyType={returnKeyType}
          onSubmitEditing={onSubmitEditing}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          accessibilityLabel={`${label}, country code plus 91`}
          accessibilityHint={errorText ?? hint}
        />
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
  box: {
    minHeight: SIZE.button,
    flexDirection: 'row',
    alignItems: 'stretch',
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.borderStrong,
    borderRadius: RADIUS.md,
    overflow: 'hidden',
  },
  boxFocused: { borderColor: c.brand },
  boxError: { borderColor: s.danger },
  prefix: {
    paddingHorizontal: SPACE.md,
    justifyContent: 'center',
    borderRightWidth: 1,
    borderRightColor: c.borderStrong,
  },
  prefixText: { color: c.fg2, fontSize: 15, fontWeight: '600' },
  input: {
    flex: 1,
    paddingHorizontal: SPACE.md,
    paddingVertical: SPACE.sm,
    color: c.fg,
    fontSize: 15,
  },
  errorText: { ...TYPE.meta, color: s.danger, fontWeight: '600' },
  hint: { ...TYPE.meta, color: c.fg3 },
}));
