import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { fetchCurrentLegalDocuments } from '../src/api/legal';
import { useTheme } from '../src/theme/ThemeContext';
import type { LegalDocument } from '../src/types';

export default function LegalScreen() {
  const { colors } = useTheme();
  const [documents, setDocuments] = useState<LegalDocument[]>([]);
  const [activeIndex, setActiveIndex] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchCurrentLegalDocuments()
      .then((response) => setDocuments(response.data))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <View style={[styles.centered, { backgroundColor: colors.background }]}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }

  const document = documents[activeIndex];

  return (
    <View style={[styles.container, { backgroundColor: colors.background }]}>
      {documents.length > 1 && (
        <View style={styles.tabRow}>
          {documents.map((doc, index) => (
            <Pressable
              key={doc.id}
              onPress={() => setActiveIndex(index)}
              style={[styles.tab, { borderColor: colors.surfaceBorder }, index === activeIndex && { borderColor: colors.primary }]}
            >
              <Text style={{ color: index === activeIndex ? colors.text : colors.textMuted, fontWeight: '600' }}>{doc.title}</Text>
            </Pressable>
          ))}
        </View>
      )}

      {document ? (
        <ScrollView style={[styles.contentBox, { borderColor: colors.surfaceBorder, backgroundColor: colors.surface }]}>
          <Text style={[styles.content, { color: colors.text }]}>{document.content}</Text>
        </ScrollView>
      ) : (
        <Text style={{ color: colors.textMuted, textAlign: 'center', marginTop: 24 }}>Nothing published yet.</Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  tabRow: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  tab: { borderWidth: 1, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 6 },
  contentBox: { flex: 1, borderWidth: 1, borderRadius: 12, padding: 16 },
  content: { fontSize: 14, lineHeight: 20 },
});
