import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Redirect, useRouter } from 'expo-router';

import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { Icon } from '../src/theme/icons';

export default function LoginScreen() {
  const { user, isLoading, login, register } = useAuth();
  const router = useRouter();
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
        <ActivityIndicator />
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
      <Text style={styles.title}>Mini Max</Text>
      <Text style={styles.subtitle}>{mode === 'login' ? 'Sign in to continue' : 'Create your account'}</Text>

      {mode === 'register' && (
        <>
          <TextInput
            style={styles.input}
            placeholder="Username"
            value={username}
            onChangeText={setUsername}
            autoCapitalize="none"
          />
          <TextInput
            style={styles.input}
            placeholder="Email"
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            keyboardType="email-address"
          />
          <TextInput
            style={styles.input}
            placeholder="Date of birth (YYYY-MM-DD)"
            value={dateOfBirth}
            onChangeText={setDateOfBirth}
          />
          <TextInput style={styles.input} placeholder="State" value={state} onChangeText={setState} />
        </>
      )}

      <TextInput
        style={styles.input}
        placeholder="Mobile number"
        value={mobile}
        onChangeText={setMobile}
        keyboardType="phone-pad"
      />
      <TextInput
        style={styles.input}
        placeholder="Password"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
      />

      {mode === 'register' && (
        <Pressable style={styles.checkboxRow} onPress={() => setAcceptTerms((value) => !value)}>
          <View style={[styles.checkbox, acceptTerms && styles.checkboxChecked]}>
            {acceptTerms && <Icon name="check" size={14} color="#fff" />}
          </View>
          <Text style={styles.checkboxLabel}>
            I agree to the{' '}
            <Text style={styles.link} onPress={() => router.push('/legal')}>
              Terms & Conditions and Privacy Policy
            </Text>
          </Text>
        </Pressable>
      )}

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable style={styles.button} onPress={handleSubmit} disabled={submitting}>
        {submitting ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.buttonText}>{mode === 'login' ? 'Sign in' : 'Create account'}</Text>
        )}
      </Pressable>

      <Pressable onPress={() => setMode(mode === 'login' ? 'register' : 'login')}>
        <Text style={styles.switchText}>
          {mode === 'login' ? "Don't have an account? Sign up" : 'Already have an account? Sign in'}
        </Text>
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#1a1025' },
  content: { flexGrow: 1, justifyContent: 'center', padding: 24 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#1a1025' },
  title: { fontSize: 32, fontWeight: '700', color: '#fff', textAlign: 'center' },
  subtitle: { fontSize: 14, color: '#C4B5FD', textAlign: 'center', marginBottom: 24 },
  input: {
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: 12,
    paddingHorizontal: 16,
    paddingVertical: 12,
    color: '#fff',
    marginBottom: 12,
  },
  checkboxRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 12 },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 1.5,
    borderColor: 'rgba(255,255,255,0.4)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxChecked: { backgroundColor: '#8B5CF6', borderColor: '#8B5CF6' },
  checkboxLabel: { flex: 1, color: '#C4B5FD', fontSize: 13 },
  link: { color: '#fff', fontWeight: '600', textDecorationLine: 'underline' },
  button: {
    backgroundColor: '#8B5CF6',
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonText: { color: '#fff', fontWeight: '600', fontSize: 16 },
  switchText: { color: '#C4B5FD', textAlign: 'center', marginTop: 16 },
  error: { color: '#F87171', marginBottom: 8, textAlign: 'center' },
});
