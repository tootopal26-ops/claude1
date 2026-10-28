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
import { useUnreadCount } from '../../src/notifications/useUnreadCount';
import { makeStyles } from '../../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, SIZE, SPACE, TYPE } from '../../src/theme/tokens';
import type { ProfileStats } from '../../src/types';
import { pushOnce } from '../../src/utils/navigation';
import { openSupportLink, SUPPORT_LINK_MESSAGES } from '../../src/utils/supportLink';
import { useWallet } from '../../src/wallet/WalletContext';

/**
 * The Profile tab, top to bottom: the player's avatar in its green ring, their name, when
 * they joined, three figures from the server (total earnings, matches played, coins
 * deposited), then the menu in this fixed order — My Profile, Wallet, Notifications,
 * Redeem Code, Settings, Terms and privacy, Customer Support, Ads Earning, Total Matches —
 * and Log out last. Every row opens something that exists; Log out asks first.
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
  const [supportMessage, setSupportMessage] = useState<string | null>(null);
  const [openingSupport, setOpeningSupport] = useState(false);
  const unread = useUnreadCount();

  const onSupport = async () => {
    if (openingSupport) return;
    setOpeningSupport(true);
    const outcome = await openSupportLink();
    setOpeningSupport(false);
    if (outcome !== 'opened') setSupportMessage(SUPPORT_LINK_MESSAGES[outcome]);
  };

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
      <ScrollView ref={scrollRef} contentContainerStyle={[styles.content, { paddingTop: insets.top + SPACE.xs, paddingBottom: bottomSpace }]} showsVerticalScrollIndicator={false}>
        <View style={[styles.column, { width: contentWidth }]}>
          <Text style={styles.screenTitle} accessibilityRole="header">
            Profile
          </Text>

          <View style={styles.identity}>
            <Avatar username={user.username} size={SIZE.avatarProfile} ring="home" pulse />
            <Text style={styles.username} numberOfLines={1}>
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
            <MenuRow artwork="profile" label="My Profile" onPress={() => pushOnce('/account')} />
            <MenuRow artwork="wallet" label="Wallet" onPress={() => pushOnce('/wallet')} />
            <MenuRow artwork="bell" label="Notifications" badge={unread > 0} detail={unread > 0 ? `${unread} new` : undefined} onPress={() => pushOnce('/notifications')} />
            <MenuRow artwork="redeem" label="Redeem Code" onPress={() => setRedeemOpen(true)} />
            <MenuRow artwork="settings" label="Settings" onPress={() => pushOnce('/settings')} />
            <MenuRow artwork="terms" label="Terms and privacy" onPress={() => pushOnce('/legal')} />
            <MenuRow artwork="support" label="Customer Support" hint="Opens the support page" onPress={onSupport} />
            <MenuRow artwork="adsEarning" label="Ads Earning" onPress={() => pushOnce('/ads-earning')} />
            <MenuRow artwork="matches" label="Total Matches" detail={stats ? String(stats.matches) : undefined} onPress={() => pushOnce('/matches')} />
          </View>

          {/* Log out is always the last item, set a little apart from the menu above it. */}
          <View style={styles.logout}>
            <MenuRow artwork="logout" label="Log out" tone="danger" onPress={() => setConfirmLogout(true)} />
          </View>
        </View>
      </ScrollView>

      <Sheet visible={supportMessage !== null} onClose={() => setSupportMessage(null)} title="Customer Support" subtitle={supportMessage ?? undefined}>
        <Button label="OK" variant="secondary" onPress={() => setSupportMessage(null)} />
      </Sheet>

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
  screenTitle: { ...TYPE.screenTitle, color: c.fg, minHeight: 44, paddingTop: SPACE.xs },
  // The identity block sits centred in the upper-middle of the header area.
  identity: { alignItems: 'center', gap: SPACE.xxs, paddingTop: SPACE.xs, paddingBottom: SPACE.xxs },
  username: { ...TYPE.screenTitle, color: c.fg, marginTop: SPACE.xs, maxWidth: '100%', textAlign: 'center' },
  meta: { ...TYPE.meta, color: c.fg3, textAlign: 'center' },
  stats: { flexDirection: 'row', gap: SPACE.xs },
  menu: { gap: SPACE.xs },
  logout: { marginTop: SPACE.xs },
}));
