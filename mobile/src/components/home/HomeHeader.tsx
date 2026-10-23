import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';

import { fetchNotifications } from '../../api/notifications';
import { PRESS_SCALE } from '../../theme/motion';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../../theme/tokens';
import { pushOnce } from '../../utils/navigation';
import { Avatar } from '../avatar/Avatar';
import { PressableScale } from '../interaction/PressableScale';
import { WalletPill } from '../wallet/WalletPill';

/**
 * Top of Home, left to right: the player's avatar in its green ring, "Welcome Back" over
 * the username, then the wallet pill and the notifications bell. The bell's dot is the
 * server's unread count, fetched each time Home is shown; a failed fetch keeps the last
 * known state rather than inventing one.
 */
export function HomeHeader({ username }: { username: string }) {
  const styles = useStyles();
  const { colors, semanticColors } = useTheme();
  const [unread, setUnread] = useState(0);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      fetchNotifications()
        .then((response) => {
          if (active) setUnread(response.meta.unread_count);
        })
        .catch(() => {});

      return () => {
        active = false;
      };
    }, []),
  );

  return (
    <View style={styles.row}>
      <Avatar username={username} size={SIZE.avatarHeader} ring="home" pulse />
      <View style={styles.text}>
        <Text style={styles.welcome} numberOfLines={1}>
          Welcome Back
        </Text>
        <Text style={styles.username} numberOfLines={1} accessibilityRole="header">
          {username}
        </Text>
      </View>
      <WalletPill />
      <PressableScale
        onPress={() => pushOnce('/notifications')}
        accessibilityRole="button"
        accessibilityLabel={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        scaleTo={PRESS_SCALE.icon}
        hitSlop={4}
      >
        <View style={styles.bell}>
          <Icon name="notifications" size={20} color={colors.fg} />
          {unread > 0 ? <View style={[styles.dot, { backgroundColor: semanticColors.danger, borderColor: colors.surface2 }]} /> : null}
        </View>
      </PressableScale>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  row: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs, minHeight: SIZE.touch + 12 },
  // A little extra room between the avatar and the greeting than between the controls.
  text: { flex: 1, minWidth: 0, marginLeft: SPACE.xxs },
  welcome: { ...TYPE.meta, color: c.fg3 },
  username: { ...TYPE.section, color: c.fg },
  bell: {
    width: SIZE.touch,
    height: SIZE.touch,
    borderRadius: RADIUS.pill,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.borderStrong,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dot: { position: 'absolute', top: 9, right: 10, width: 10, height: 10, borderRadius: 5, borderWidth: 2 },
}));
