import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';

import { acceptCurrentLegalDocuments } from '../api/legal';
import { useAuth } from '../auth/AuthContext';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import { Button } from './Button';
import { Notice } from './Notice';

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
  const styles = useStyles();
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
      <View style={styles.centered}>
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>{document.title}</Text>
      <Text style={styles.subtitle}>
        {outstandingLegalDocuments.length > 1
          ? `Please review and accept (${activeIndex + 1} of ${outstandingLegalDocuments.length})`
          : 'This has been updated — please review and accept to continue.'}
      </Text>

      <ScrollView style={styles.contentBox}>
        <Text style={styles.content}>{document.content}</Text>
      </ScrollView>

      {error && (
        <View style={styles.errorBox}>
          <Notice tone="danger">{error}</Notice>
        </View>
      )}

      <Button label="I Accept" onPress={handleAccept} loading={submitting} style={styles.accept} />

      <Pressable onPress={() => logout()} accessibilityRole="button" hitSlop={8}>
        <Text style={styles.logoutText}>Log out instead</Text>
      </Pressable>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1, padding: 24, paddingTop: 64, backgroundColor: c.bg },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: c.bg },
  title: { color: c.fg, fontSize: 22, fontWeight: '700', marginBottom: 4 },
  subtitle: { color: c.fg2, fontSize: 13, marginBottom: 16 },
  contentBox: {
    flex: 1,
    borderWidth: 1,
    borderColor: c.border,
    backgroundColor: c.surface,
    borderRadius: 12,
    padding: 16,
    marginBottom: 16,
  },
  content: { color: c.fg, fontSize: 14, lineHeight: 20 },
  errorBox: { marginBottom: 12 },
  accept: { marginBottom: 12 },
  logoutText: { color: c.fg2, textAlign: 'center', fontSize: 13, fontWeight: '600' },
}));
