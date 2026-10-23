import { useFocusEffect, useScrollToTop } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { ScrollView, Text, useWindowDimensions, View, type ScrollViewInstance } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { fetchProfileStats } from '../../src/api/profile';
import { useAuth } from '../../src/auth/AuthContext';
import { AmbientGlow } from '../../src/components/AmbientGlow';
import { Avatar } from '../../src/components/avatar/Avatar';
import { Button } from '../../src/components/Button';
import { MenuRow } from '../../src/components/cards/MenuRow';
import { StatCard } from '../../src/components/cards/StatCard';
import { useBottomNavSpace } from '../../src/components/navigation/BottomNav';
import { RedeemCodeDialog } from '../../src/components/RedeemCodeDialog';
import { Sheet } from '../../src/components/sheet/Sheet';
import { glassPanel } from '../../src/theme/glass';
import { makeStyles } from '../../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE, TYPE } from '../../src/theme/tokens';
import type { ProfileStats } from '../../src/types';
import { pushOnce } from '../../src/utils/navigation';
import { useWallet } from '../../src/wallet/WalletContext';

/**
 * The Profile tab: the player's avatar in its green ring, their name, three figures from the
 * server (total earnings, matches played, coins deposited) and the account menu. Every row
 * opens something that exists; Logout asks first.
 */
export default function ProfileScreen() {
  const { user, logout } = useAuth();
  const styles = useStyles();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const bottomSpace = useBottomNavSpace();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const scrollRef = useRef<ScrollViewInstance>(null);
  useScrollToTop(scrollRef);
  const { refresh: refreshWallet } = useWallet();
  const [stats, setStats] = useState<ProfileStats | null>(null);
  const [statsFailed, setStatsFailed] = useState(false);
  const [redeemOpen, setRedeemOpen] = useState(false);
  const [confirmLogout, setConfirmLogout] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      fetchProfileStats()
        .then((response) => {
          if (!active) return;
          setStats(response.data);
          setStatsFailed(false);
        })
        .catch(() => {
          if (active) setStatsFailed(true);
        });

      return () => {
        active = false;
      };
    }, []),
  );

  if (!user) return null;

  const memberSince = user.member_since ? new Date(user.member_since).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }) : null;

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView ref={scrollRef} contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.lg, paddingBottom: bottomSpace }]} showsVerticalScrollIndicator={false}>
        <View style={[styles.column, { width: contentWidth }]}>
          <View style={styles.identity}>
            <Avatar username={user.username} size={SIZE.avatarProfile} ring="home" pulse />
            <Text style={styles.username} numberOfLines={1} accessibilityRole="header">
              {user.username}
            </Text>
            <Text style={styles.meta}>{memberSince ? `Member since ${memberSince}` : `Mobile ending ${user.mobile.slice(-4)}`}</Text>
          </View>

          <View style={styles.stats}>
            <StatCard label="Total Earnings" value={stats ? { coins: stats.total_earnings } : null} failed={statsFailed && !stats} />
            <StatCard label="Matches" value={stats ? { count: stats.matches } : null} failed={statsFailed && !stats} />
            <StatCard label="Deposits" value={stats ? { coins: stats.deposits_total } : null} failed={statsFailed && !stats} />
          </View>

          <View style={styles.menu}>
            <MenuRow icon="user" label="My Profile" onPress={() => pushOnce('/account')} />
            <MenuRow icon="wallet" label="Wallet" onPress={() => pushOnce('/wallet')} />
            <MenuRow icon="notifications" label="Notifications" onPress={() => pushOnce('/notifications')} />
            <MenuRow icon="redeem" label="Redeem Code" onPress={() => setRedeemOpen(true)} />
            <MenuRow icon="settings" label="Settings" onPress={() => pushOnce('/settings')} />
            <MenuRow icon="file-text" label="Terms and privacy" onPress={() => pushOnce('/legal')} last />
          </View>

          <View style={styles.menu}>
            <MenuRow icon="logout" label="Log out" tone="danger" onPress={() => setConfirmLogout(true)} last />
          </View>
        </View>
      </ScrollView>

      <RedeemCodeDialog visible={redeemOpen} onClose={() => setRedeemOpen(false)} onRedeemed={() => refreshWallet({ force: true })} />

      <Sheet visible={confirmLogout} onClose={() => setConfirmLogout(false)} title="Log out?" subtitle="You will need your mobile number and password to sign in again." dismissible={!loggingOut}>
        <Button
          label="Log out"
          variant="danger"
          icon="logout"
          loading={loggingOut}
          onPress={async () => {
            setLoggingOut(true);
            await logout();
          }}
        />
        <Button label="Cancel" variant="secondary" onPress={() => setConfirmLogout(false)} disabled={loggingOut} />
      </Sheet>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center' },
  column: { gap: SPACE.lg },
  identity: { alignItems: 'center', gap: SPACE.xxs },
  username: { ...TYPE.screenTitle, color: c.fg, marginTop: SPACE.xs, maxWidth: '100%' },
  meta: { ...TYPE.meta, color: c.fg3 },
  stats: { flexDirection: 'row', gap: SPACE.xs },
  menu: { ...glassPanel(c), borderRadius: RADIUS.lg, overflow: 'hidden' },
}));
