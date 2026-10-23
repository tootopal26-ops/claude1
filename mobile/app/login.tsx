import { useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, View, type TextInputInstance } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { BrandLogo } from '../src/components/BrandLogo';
import { Button } from '../src/components/Button';
import { SegmentedControl } from '../src/components/controls/SegmentedControl';
import { Notice } from '../src/components/Notice';
import { TextField } from '../src/components/TextField';
import { glassPanel } from '../src/theme/glass';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../src/theme/tokens';

type Mode = 'login' | 'register';
type Errors = Record<string, string>;

const MODES: { value: Mode; label: string }[] = [
  { value: 'login', label: 'Sign in' },
  { value: 'register', label: 'Create account' },
];

/**
 * Sign in and registration. Every field is labelled and uses the right keyboard; the
 * password can be shown; the server's own validation messages appear under the fields they
 * belong to (anything else above the button). The form scrolls above the keyboard.
 * Signing in never skips the server: the app only continues once it returns a session.
 */
export default function LoginScreen() {
  const { login, register } = useAuth();
  const router = useRouter();
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const [mode, setMode] = useState<Mode>('login');
  const [mobile, setMobile] = useState('');
  const [password, setPassword] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [dateOfBirth, setDateOfBirth] = useState('');
  const [state, setState] = useState('');
  const [acceptTerms, setAcceptTerms] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const passwordRef = useRef<TextInputInstance>(null);

  const clear = (field: string) => {
    setFormError(null);
    if (errors[field]) {
      setErrors((current) => {
        const next = { ...current };
        delete next[field];
        return next;
      });
    }
  };

  const validate = (): Errors => {
    const found: Errors = {};
    if (mode === 'register') {
      if (!username.trim()) found.username = 'Choose a username.';
      if (!email.trim()) found.email = 'Enter your email address.';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(dateOfBirth.trim())) found.date_of_birth = 'Use the format YYYY-MM-DD.';
      if (!state.trim()) found.state = 'Enter the state you live in.';
      if (!acceptTerms) found.accept_terms = 'Accept the terms to create an account.';
    }
    if (!mobile.trim()) found.mobile = 'Enter your mobile number.';
    if (!password) found.password = 'Enter your password.';

    return found;
  };

  async function handleSubmit() {
    if (submitting) return;
    const found = validate();
    setErrors(found);
    setFormError(null);
    if (Object.keys(found).length > 0) return;

    setSubmitting(true);
    try {
      if (mode === 'login') {
        await login({ mobile: mobile.trim(), password });
      } else {
        await register({
          username: username.trim(),
          mobile: mobile.trim(),
          email: email.trim(),
          date_of_birth: dateOfBirth.trim(),
          state: state.trim(),
          password,
          password_confirmation: password,
          accept_terms: acceptTerms,
        });
      }
    } catch (error) {
      if (error instanceof ApiError) {
        const fieldErrors: Errors = {};
        Object.entries(error.errors ?? {}).forEach(([field, messages]) => {
          fieldErrors[field] = messages[0];
        });
        setErrors(fieldErrors);
        const shown = Object.keys(fieldErrors).some((field) => FIELDS[mode].includes(field));
        setFormError(error.status === 429 ? 'Too many attempts. Wait a minute and try again.' : shown ? null : error.message);
      } else {
        setFormError("We couldn't reach Mini Max. Check your connection and try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xl, paddingBottom: insets.bottom + SPACE.xl }]}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.column}>
            <BrandLogo width={150} style={styles.logo} />
            <Text style={styles.heading} accessibilityRole="header">
              {mode === 'login' ? 'Welcome back' : 'Join Mini Max'}
            </Text>
            <Text style={styles.subtitle}>{mode === 'login' ? 'Sign in with your mobile number.' : 'Create your player account in a minute.'}</Text>

            <View style={styles.card}>
              <SegmentedControl
                options={MODES}
                value={mode}
                onChange={(next) => {
                  setMode(next);
                  setErrors({});
                  setFormError(null);
                }}
                accessibilityLabel="Sign in or create an account"
              />

              {mode === 'register' && (
                <>
                  <TextField
                    label="Username"
                    placeholder="Your player name"
                    value={username}
                    onChangeText={(value) => {
                      setUsername(value);
                      clear('username');
                    }}
                    autoCapitalize="none"
                    autoCorrect={false}
                    autoComplete="username-new"
                    errorText={errors.username}
                  />
                  <TextField
                    label="Email"
                    placeholder="name@example.com"
                    value={email}
                    onChangeText={(value) => {
                      setEmail(value);
                      clear('email');
                    }}
                    autoCapitalize="none"
                    autoCorrect={false}
                    keyboardType="email-address"
                    autoComplete="email"
                    errorText={errors.email}
                  />
                  <TextField
                    label="Date of birth"
                    placeholder="YYYY-MM-DD"
                    value={dateOfBirth}
                    onChangeText={(value) => {
                      setDateOfBirth(value.replace(/[^\d-]/g, '').slice(0, 10));
                      clear('date_of_birth');
                    }}
                    keyboardType="numbers-and-punctuation"
                    maxLength={10}
                    hint="You must be 18 or older."
                    errorText={errors.date_of_birth}
                  />
                  <TextField
                    label="State"
                    placeholder="The state you live in"
                    value={state}
                    onChangeText={(value) => {
                      setState(value);
                      clear('state');
                    }}
                    autoCapitalize="words"
                    errorText={errors.state}
                  />
                </>
              )}

              <TextField
                label="Mobile number"
                placeholder="Your mobile number"
                value={mobile}
                onChangeText={(value) => {
                  setMobile(value.replace(/[^\d+]/g, '').slice(0, 20));
                  clear('mobile');
                }}
                keyboardType="phone-pad"
                autoComplete="tel"
                textContentType="telephoneNumber"
                maxLength={20}
                returnKeyType="next"
                onSubmitEditing={() => passwordRef.current?.focus()}
                errorText={errors.mobile}
              />
              <TextField
                ref={passwordRef}
                label="Password"
                placeholder={mode === 'login' ? 'Your password' : 'At least 8 characters'}
                value={password}
                onChangeText={(value) => {
                  setPassword(value);
                  clear('password');
                }}
                password
                autoComplete={mode === 'login' ? 'password' : 'password-new'}
                returnKeyType="go"
                onSubmitEditing={handleSubmit}
                errorText={errors.password}
              />

              {mode === 'register' && (
                <View style={styles.termsBlock}>
                  <Pressable
                    style={styles.checkboxRow}
                    onPress={() => {
                      setAcceptTerms((value) => !value);
                      clear('accept_terms');
                    }}
                    accessibilityRole="checkbox"
                    aria-checked={acceptTerms}
                    accessibilityLabel="I agree to the Terms and Conditions and Privacy Policy"
                    hitSlop={6}
                  >
                    <View style={[styles.checkbox, acceptTerms && styles.checkboxChecked]}>{acceptTerms && <Icon name="check" size={14} color={colors.onBrand} />}</View>
                    <Text style={styles.checkboxLabel}>
                      I agree to the{' '}
                      <Text style={styles.link} onPress={() => router.push('/legal')} accessibilityRole="link">
                        Terms and Conditions and Privacy Policy
                      </Text>
                    </Text>
                  </Pressable>
                  {errors.accept_terms ? <Text style={styles.fieldError}>{errors.accept_terms}</Text> : null}
                </View>
              )}

              {formError ? <Notice tone="danger">{formError}</Notice> : null}

              <Button label={mode === 'login' ? 'Sign in' : 'Create account'} onPress={handleSubmit} loading={submitting} />
            </View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const FIELDS: Record<Mode, string[]> = {
  login: ['mobile', 'password'],
  register: ['username', 'email', 'date_of_birth', 'state', 'mobile', 'password', 'accept_terms'],
};

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  screen: { flex: 1 },
  flex: { flex: 1 },
  content: { flexGrow: 1, justifyContent: 'center', alignItems: 'center', paddingHorizontal: SPACE.md },
  column: { width: '100%', maxWidth: 440 },
  logo: { alignSelf: 'center', marginBottom: SPACE.md },
  heading: { ...TYPE.hero, color: c.fg, textAlign: 'center' },
  subtitle: { ...TYPE.body, color: c.fg2, textAlign: 'center', marginTop: SPACE.xxs, marginBottom: SPACE.lg },
  card: { ...glassPanel(c), borderRadius: RADIUS.xl, padding: SPACE.md, gap: SPACE.md },
  termsBlock: { gap: SPACE.xxs },
  checkboxRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, minHeight: 44 },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 1.5,
    borderColor: c.borderStrong,
    backgroundColor: c.surface2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxChecked: { backgroundColor: c.brand, borderColor: c.brand },
  checkboxLabel: { ...TYPE.body, flex: 1, color: c.fg2 },
  link: { color: c.brandText, fontWeight: '600', textDecorationLine: 'underline' },
  fieldError: { ...TYPE.meta, color: s.danger, fontWeight: '600' },
}));
