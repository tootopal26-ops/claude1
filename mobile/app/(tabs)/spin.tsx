import { ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AmbientGlow } from '../../src/components/AmbientGlow';
import { Button } from '../../src/components/Button';
import { useBottomNavSpace } from '../../src/components/navigation/BottomNav';
import { NavIcon } from '../../src/components/navigation/NavIcons';
import { Notice } from '../../src/components/Notice';
import { glassPanel } from '../../src/theme/glass';
import { makeStyles, useTheme } from '../../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../../src/theme/tokens';
import { pushOnce } from '../../src/utils/navigation';

/**
 * The Spin tab — the new nav bar's 4th destination. Rather than invent a separate reward
 * economy, it's the front door to the earning features that already exist and are already
 * backend-confirmed: Ads Earning (watch an ad, verified server-side before anything is
 * credited) and the Daily Reward, which Home already offers automatically once a day. This
 * screen never shows a coin amount it hasn't actually been given by the server.
 */
export default function SpinScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const bottomSpace = useBottomNavSpace();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xs, paddingBottom: bottomSpace }]} showsVerticalScrollIndicator={false}>
        <View style={[styles.column, { width: contentWidth }]}>
          <Text style={styles.screenTitle} accessibilityRole="header">
            Spin &amp; Win
          </Text>

          <View style={styles.hero}>
            <View style={styles.wheel}>
              <NavIcon name="spin" filled size={56} color={colors.brandText} />
            </View>
            <Text style={styles.heroTitle}>More ways to earn coins</Text>
            <Text style={styles.heroBody}>Every reward here is confirmed by Mini Max before it ever reaches your wallet.</Text>
          </View>

          <View style={styles.menu}>
            <View style={styles.row}>
              <View style={styles.rowText}>
                <Text style={styles.rowTitle}>Ads Earning</Text>
                <Text style={styles.rowHint}>Watch a short ad, get coins once it's verified.</Text>
              </View>
              <Button label="Open" variant="secondary" size="sm" onPress={() => pushOnce('/ads-earning')} />
            </View>
          </View>

          <Notice tone="info">Your free Daily Reward appears on Home automatically when it's ready to claim.</Notice>
        </View>
      </ScrollView>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center' },
  column: { gap: SPACE.md },
  screenTitle: { ...TYPE.screenTitle, color: c.fg, minHeight: 44, paddingTop: SPACE.xs },
  hero: { alignItems: 'center', gap: SPACE.xs, paddingVertical: SPACE.sm },
  wheel: { width: 96, height: 96, borderRadius: 48, alignItems: 'center', justifyContent: 'center', backgroundColor: c.brandSoft, borderWidth: 1, borderColor: c.border, marginBottom: SPACE.xs },
  heroTitle: { ...TYPE.section, color: c.fg, textAlign: 'center' },
  heroBody: { ...TYPE.body, color: c.fg2, textAlign: 'center' },
  menu: { ...glassPanel(c), borderRadius: RADIUS.lg, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, padding: SPACE.md },
  rowText: { flex: 1, gap: 2 },
  rowTitle: { ...TYPE.bodyStrong, fontSize: 15, color: c.fg },
  rowHint: { ...TYPE.meta, color: c.fg3 },
}));
