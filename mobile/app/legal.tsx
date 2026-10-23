import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';

import { fetchCurrentLegalDocuments } from '../src/api/legal';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import type { LegalDocument } from '../src/types';

export default function LegalScreen() {
  const { colors } = useTheme();
  const styles = useStyles();
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
      <View style={styles.centered}>
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  const document = documents[activeIndex];

  return (
    <View style={styles.container}>
      {documents.length > 1 && (
        <View style={styles.tabRow}>
          {documents.map((doc, index) => (
            <Pressable
              key={doc.id}
              onPress={() => setActiveIndex(index)}
              style={[styles.tab, index === activeIndex && styles.tabActive]}
              accessibilityRole="tab"
              aria-selected={index === activeIndex}
            >
              <Text style={[styles.tabText, index === activeIndex && styles.tabTextActive]}>{doc.title}</Text>
            </Pressable>
          ))}
        </View>
      )}

      {document ? (
        <ScrollView style={styles.contentBox}>
          <Text style={styles.content}>{document.content}</Text>
        </ScrollView>
      ) : (
        <Text style={styles.empty}>Nothing published yet.</Text>
      )}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1, padding: 16 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  tabRow: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  tab: {
    borderWidth: 1,
    borderColor: c.border,
    backgroundColor: c.surface2,
    borderRadius: 999,
    paddingHorizontal: 14,
    paddingVertical: 8,
  },
  tabActive: { backgroundColor: c.brandSoft, borderColor: c.brand },
  tabText: { color: c.fg2, fontWeight: '600' },
  tabTextActive: { color: c.fg },
  contentBox: {
    flex: 1,
    borderWidth: 1,
    borderColor: c.border,
    backgroundColor: c.surface,
    borderRadius: 12,
    padding: 16,
  },
  content: { color: c.fg, fontSize: 14, lineHeight: 20 },
  empty: { color: c.fg2, textAlign: 'center', marginTop: 24 },
}));
