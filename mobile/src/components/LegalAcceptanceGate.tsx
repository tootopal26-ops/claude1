import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { acceptCurrentLegalDocuments } from '../api/legal';
import { useAuth } from '../auth/AuthContext';
import { useTheme } from '../theme/ThemeContext';

/**
 * Rendered in place of the whole app (see app/_layout.tsx) whenever
 * AuthContext reports an outstanding legal document — either right
 * after a fresh login/registration (there should be none then, since
 * registration itself records acceptance) or after staff publish a
 * revision to a document this player already accepted.
 */
export function LegalAcceptanceGate() {
  const { outstandingLegalDocuments, refreshLegalStatus, logout } = useAuth();
  const { colors } = useTheme();
  const [activeIndex, setActiveIndex] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const document = outstandingLegalDocuments[activeIndex];

  async function handleAccept() {
    setSubmitting(true);
    setError(null);
    try {
      await acceptCurrentLegalDocuments();
      await refreshLegalStatus();
    } catch {
      setError('Could not record your acceptance. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  if (!document) {
    return (
      <View style={[styles.centered, { backgroundColor: colors.background }]}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      <Text style={[styles.title, { color: colors.text }]}>{document.title}</Text>
      <Text style={[styles.subtitle, { color: colors.textMuted }]}>
        {outstandingLegalDocuments.length > 1
          ? `Please review and accept (${activeIndex + 1} of ${outstandingLegalDocuments.length})`
          : 'This has been updated — please review and accept to continue.'}
      </Text>

      <ScrollView style={[styles.contentBox, { borderColor: colors.surfaceBorder, backgroundColor: colors.surface }]}>
        <Text style={[styles.content, { color: colors.text }]}>{document.content}</Text>
      </ScrollView>

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable
        style={[styles.button, { backgroundColor: colors.primary }]}
        onPress={handleAccept}
        disabled={submitting}
      >
        {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>I Accept</Text>}
      </Pressable>

      <Pressable onPress={() => logout()}>
        <Text style={[styles.logoutText, { color: colors.textMuted }]}>Log out instead</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 24, paddingTop: 64 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  title: { fontSize: 22, fontWeight: '700', marginBottom: 4 },
  subtitle: { fontSize: 13, marginBottom: 16 },
  contentBox: { flex: 1, borderWidth: 1, borderRadius: 12, padding: 16, marginBottom: 16 },
  content: { fontSize: 14, lineHeight: 20 },
  button: { borderRadius: 12, paddingVertical: 14, alignItems: 'center', marginBottom: 12 },
  buttonText: { color: '#fff', fontWeight: '700', fontSize: 16 },
  logoutText: { textAlign: 'center', fontSize: 13 },
  error: { color: '#F87171', marginBottom: 8, textAlign: 'center' },
});
