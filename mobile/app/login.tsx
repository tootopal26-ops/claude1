import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { Redirect, useRouter } from 'expo-router';

import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { BrandLogo } from '../src/components/BrandLogo';
import { Button } from '../src/components/Button';
import { Notice } from '../src/components/Notice';
import { TextField } from '../src/components/TextField';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';

export default function LoginScreen() {
  const { user, isLoading, login, register } = useAuth();
  const router = useRouter();
  const { colors } = useTheme();
  const styles = useStyles();
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [mobile, setMobile] = useState('');
  const [password, setPassword] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [dateOfBirth, setDateOfBirth] = useState('');
  const [state, setState] = useState('');
  const [acceptTerms, setAcceptTerms] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  if (user) {
    return <Redirect href="/" />;
  }

  async function handleSubmit() {
    setError(null);
    setSubmitting(true);
    try {
      if (mode === 'login') {
        await login({ mobile, password });
      } else {
        await register({
          username,
          mobile,
          email,
          date_of_birth: dateOfBirth,
          state,
          password,
          password_confirmation: password,
          accept_terms: acceptTerms,
        });
      }
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('Something went wrong. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <BrandLogo width={200} style={styles.logo} />
      <Text style={styles.subtitle}>{mode === 'login' ? 'Sign in to continue' : 'Create your account'}</Text>

      <View style={styles.form}>
        {mode === 'register' && (
          <>
            <TextField label="Username" placeholder="Username" value={username} onChangeText={setUsername} autoCapitalize="none" />
            <TextField
              label="Email"
              placeholder="Email"
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              keyboardType="email-address"
            />
            <TextField
              label="Date of birth"
              placeholder="Date of birth (YYYY-MM-DD)"
              value={dateOfBirth}
              onChangeText={setDateOfBirth}
            />
            <TextField label="State" placeholder="State" value={state} onChangeText={setState} />
          </>
        )}

        <TextField
          label="Mobile number"
          placeholder="Mobile number"
          value={mobile}
          onChangeText={setMobile}
          keyboardType="phone-pad"
        />
        <TextField
          label="Password"
          placeholder="Password"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
        />

        {mode === 'register' && (
          <Pressable
            style={styles.checkboxRow}
            onPress={() => setAcceptTerms((value) => !value)}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: acceptTerms }}
          >
            <View style={[styles.checkbox, acceptTerms && styles.checkboxChecked]}>
              {acceptTerms && <Icon name="check" size={14} color={colors.onBrand} />}
            </View>
            <Text style={styles.checkboxLabel}>
              I agree to the{' '}
              <Text style={styles.link} onPress={() => router.push('/legal')}>
                Terms & Conditions and Privacy Policy
              </Text>
            </Text>
          </Pressable>
        )}

        {error && <Notice tone="danger">{error}</Notice>}

        <Button
          label={mode === 'login' ? 'Sign in' : 'Create account'}
          onPress={handleSubmit}
          loading={submitting}
          style={styles.submit}
        />
      </View>

      <Pressable onPress={() => setMode(mode === 'login' ? 'register' : 'login')} accessibilityRole="button" hitSlop={8}>
        <Text style={styles.switchText}>
          {mode === 'login' ? "Don't have an account? Sign up" : 'Already have an account? Sign in'}
        </Text>
      </Pressable>
    </ScrollView>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1 },
  content: { flexGrow: 1, justifyContent: 'center', padding: 24 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  logo: { alignSelf: 'center', marginBottom: 8 },
  subtitle: { fontSize: 14, color: c.fg2, textAlign: 'center', marginBottom: 24 },
  form: { gap: 14 },
  checkboxRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
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
  checkboxLabel: { flex: 1, color: c.fg2, fontSize: 13 },
  link: { color: c.brandText, fontWeight: '600', textDecorationLine: 'underline' },
  submit: { marginTop: 4 },
  switchText: { color: c.brandText, textAlign: 'center', marginTop: 20, fontWeight: '600' },
}));
