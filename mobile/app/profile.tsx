import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { Link, Redirect } from 'expo-router';

import { useAuth } from '../src/auth/AuthContext';
import { Button } from '../src/components/Button';
import { RedeemCodeDialog } from '../src/components/RedeemCodeDialog';
import { Icon, type IconName } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';

export default function ProfileScreen() {
  const { user, isLoading, logout } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const [redeemOpen, setRedeemOpen] = useState(false);

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  if (!user) {
    return <Redirect href="/login" />;
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.header}>
        <View style={styles.avatar} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          <Text style={styles.avatarText}>{user.username.slice(0, 2).toUpperCase()}</Text>
        </View>
        <View style={styles.headerText}>
          <Text style={styles.username} numberOfLines={1}>
            {user.username}
          </Text>
          <Text style={styles.mobile}>Mobile ending {user.mobile.slice(-4)}</Text>
        </View>
      </View>

      <Pressable
        onPress={() => setRedeemOpen(true)}
        accessibilityRole="button"
        accessibilityLabel="Redeem Code. Have a reward code? Unlock your coins here."
        style={({ pressed }) => [styles.redeemCard, pressed && styles.redeemCardPressed]}
      >
        <View style={styles.redeemIcon}>
          <Icon name="redeem" size={30} color={colors.fg} accent={colors.brandText} />
        </View>
        <View style={styles.redeemText}>
          <Text style={styles.redeemTitle}>Redeem Code</Text>
          <Text style={styles.redeemSubtitle}>Have a reward code? Unlock your coins here.</Text>
        </View>
        <Icon name="chevron-right" size={18} color={colors.fg3} />
      </Pressable>

      <Text style={styles.sectionTitle}>Account</Text>
      <View style={styles.group}>
        <Row href="/wallet" icon="wallet" label="Wallet" />
        <Row href="/settings" icon="presets" label="Appearance" />
        <Row href="/legal" icon="audit" label="Terms and privacy" last />
      </View>

      <Button label="Log out" variant="secondary" onPress={() => logout()} style={styles.logout} />

      <RedeemCodeDialog visible={redeemOpen} onClose={() => setRedeemOpen(false)} />
    </ScrollView>
  );
}

function Row({ href, icon, label, last }: { href: '/wallet' | '/settings' | '/legal'; icon: IconName; label: string; last?: boolean }) {
  const { colors } = useTheme();
  const styles = useStyles();

  return (
    <Link href={href} asChild>
      <Pressable accessibilityRole="button" style={({ pressed }) => [styles.row, !last && styles.rowDivider, pressed && styles.rowPressed]}>
        <Icon name={icon} size={20} color={colors.fg2} />
        <Text style={styles.rowLabel}>{label}</Text>
        <Icon name="chevron-right" size={16} color={colors.fg3} />
      </Pressable>
    </Link>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  content: { padding: 16, paddingBottom: 32, gap: 16 },
  header: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 4 },
  avatar: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: c.brandSoft,
    borderWidth: 1,
    borderColor: c.borderStrong,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { color: c.brandText, fontSize: 18, fontWeight: '800' },
  headerText: { flex: 1 },
  username: { color: c.fg, fontSize: 20, fontWeight: '700' },
  mobile: { color: c.fg3, fontSize: 13, marginTop: 2 },
  // Glass card: translucent surface and a hairline border, with a restrained brand-tinted icon tile.
  redeemCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    minHeight: 76,
    padding: 14,
    borderRadius: 18,
    backgroundColor: `${c.surface2}e6`,
    borderWidth: 1,
    borderColor: c.borderStrong,
  },
  redeemCardPressed: { backgroundColor: c.surface3 },
  redeemIcon: {
    width: 52,
    height: 52,
    borderRadius: 14,
    backgroundColor: c.brandSoft,
    borderWidth: 1,
    borderColor: c.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  redeemText: { flex: 1 },
  redeemTitle: { color: c.fg, fontSize: 16, fontWeight: '700' },
  redeemSubtitle: { color: c.fg2, fontSize: 13, lineHeight: 18, marginTop: 2 },
  sectionTitle: { color: c.fg3, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.6, marginTop: 8, marginBottom: -4 },
  group: { backgroundColor: c.surface, borderRadius: 16, borderWidth: 1, borderColor: c.border, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 52, paddingHorizontal: 14 },
  rowDivider: { borderBottomWidth: 1, borderBottomColor: c.border },
  rowPressed: { backgroundColor: c.surface2 },
  rowLabel: { flex: 1, color: c.fg, fontSize: 15, fontWeight: '600' },
  logout: { marginTop: 8 },
}));
