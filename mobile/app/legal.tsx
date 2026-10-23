import { useCallback, useEffect, useState } from 'react';
import { ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { fetchCurrentLegalDocuments } from '../src/api/legal';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { SegmentedControl } from '../src/components/controls/SegmentedControl';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { glassPanel } from '../src/theme/glass';
import { makeStyles } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../src/theme/tokens';
import type { LegalDocument } from '../src/types';
import { formatDate } from '../src/utils/time';

/**
 * The current Terms and Conditions and Privacy Policy exactly as published by staff, at a
 * readable width with comfortable line spacing. A failed load says so and offers a retry —
 * it is never shown as "nothing published".
 */
export default function LegalScreen() {
  const styles = useStyles();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const [documents, setDocuments] = useState<LegalDocument[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [active, setActive] = useState<string>('0');

  const load = useCallback(async () => {
    setFailed(false);
    try {
      const response = await fetchCurrentLegalDocuments();
      setDocuments(response.data);
    } catch {
      setFailed(true);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const document = documents?.[Number(active)] ?? null;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}>
        <View style={[styles.column, { width: contentWidth }]}>
          {failed && documents === null ? (
            <ErrorState title="Couldn't load the terms" onRetry={() => load()} />
          ) : documents === null ? (
            <View style={styles.skeletons}>
              <Skeleton height={40} radius={RADIUS.md} />
              <Skeleton height={320} radius={RADIUS.lg} />
            </View>
          ) : documents.length === 0 ? (
            <EmptyState icon="file-text" title="Nothing published yet" message="The terms appear here once Mini Max publishes them." />
          ) : (
            <>
              {documents.length > 1 ? (
                <SegmentedControl
                  options={documents.map((doc, index) => ({ value: String(index), label: doc.type === 'privacy_policy' ? 'Privacy' : 'Terms' }))}
                  value={active}
                  onChange={setActive}
                  accessibilityLabel="Legal document"
                />
              ) : null}
              {document ? (
                <View style={styles.card}>
                  <Text style={styles.title} accessibilityRole="header">
                    {document.title}
                  </Text>
                  <Text style={styles.meta}>
                    Version {document.version} · published {formatDate(document.published_at)}
                  </Text>
                  <Text style={styles.body} selectable>
                    {document.content}
                  </Text>
                </View>
              ) : null}
            </>
          )}
        </View>
      </ScrollView>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.md },
  column: { gap: SPACE.md },
  skeletons: { gap: SPACE.md },
  card: { ...glassPanel(c), borderRadius: RADIUS.lg, padding: SPACE.lg, gap: SPACE.xs },
  title: { ...TYPE.section, color: c.fg },
  meta: { ...TYPE.meta, color: c.fg3, marginBottom: SPACE.xs },
  body: { ...TYPE.body, fontSize: 15, lineHeight: 24, color: c.fg2 },
}));
