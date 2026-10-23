import { useCallback, useEffect, useState } from 'react';
import { FlatList, RefreshControl, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { fetchNotifications, markNotificationRead } from '../src/api/notifications';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { PressableScale } from '../src/components/interaction/PressableScale';
import { EmptyState, ErrorState, Skeleton } from '../src/components/states/States';
import { glassPanel } from '../src/theme/glass';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SPACE, TYPE } from '../src/theme/tokens';
import type { AppNotification } from '../src/types';
import { pushOnce } from '../src/utils/navigation';
import { formatDateTime } from '../src/utils/time';

/** The tournament a notification points at, from its deep link or tournament id. */
function tournamentIdOf(notification: AppNotification): string | null {
  const fromLink = notification.data?.deep_link?.match(/^\/?tournament\/(\d+)$/)?.[1];
  const fromId = notification.data?.tournament_id;

  return fromLink ?? (typeof fromId === 'string' || typeof fromId === 'number' ? String(fromId) : null);
}

/**
 * The player's notifications, newest first. Unread ones are tinted with a dot; opening one
 * marks it read and, when it is about a tournament, opens that tournament.
 */
export default function NotificationsScreen() {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const [notifications, setNotifications] = useState<AppNotification[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await fetchNotifications();
      setNotifications(response.data);
      setFailed(false);
    } catch {
      setFailed(true);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const open = (notification: AppNotification) => {
    if (!notification.read) {
      setNotifications((current) => current?.map((item) => (item.id === notification.id ? { ...item, read: true } : item)) ?? null);
      markNotificationRead(notification.id).catch(() => {
        // Non-fatal: the next refresh reconciles with the server.
      });
    }
    const tournamentId = tournamentIdOf(notification);
    if (tournamentId) pushOnce(`/tournament/${tournamentId}`);
  };

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <FlatList
        data={notifications ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + SPACE.xxl }]}
        refreshControl={
          <RefreshControl
            tintColor={colors.brandText}
            colors={[colors.brand]}
            progressBackgroundColor={colors.surface3}
            refreshing={refreshing}
            onRefresh={async () => {
              setRefreshing(true);
              await load();
              setRefreshing(false);
            }}
          />
        }
        ItemSeparatorComponent={() => <View style={{ height: SPACE.xs }} />}
        renderItem={({ item }) => {
          const linked = tournamentIdOf(item) !== null;

          return (
            <PressableScale
              onPress={() => open(item)}
              accessibilityRole="button"
              accessibilityHint={linked ? 'Opens the tournament' : undefined}
              layoutStyle={{ width: contentWidth }}
              style={[styles.card, !item.read && styles.unread]}
            >
              <View style={[styles.icon, !item.read && styles.iconUnread, item.read && styles.iconRead]}>
                <Icon name={linked ? 'tournaments' : 'notifications'} size={20} />
              </View>
              <View style={styles.text}>
                <View style={styles.titleRow}>
                  <Text style={[styles.title, !item.read && styles.titleUnread]} numberOfLines={2}>
                    {item.title}
                  </Text>
                  {!item.read ? <View style={styles.dot} accessibilityRole="image" accessibilityLabel="Unread" /> : null}
                </View>
                <Text style={styles.body}>{item.body}</Text>
                <Text style={styles.time}>{formatDateTime(item.created_at)}</Text>
              </View>
              {linked ? <Icon name="chevron-right" size={16} color={colors.fg3} /> : null}
            </PressableScale>
          );
        }}
        ListEmptyComponent={
          <View style={{ width: contentWidth }}>
            {failed && notifications === null ? (
              <ErrorState title="Couldn't load notifications" onRetry={() => load()} />
            ) : notifications === null ? (
              <View style={styles.skeletons}>
                {[0, 1, 2, 3].map((index) => (
                  <Skeleton key={index} height={84} radius={RADIUS.lg} />
                ))}
              </View>
            ) : (
              <EmptyState icon="notifications" title="No notifications yet" message="Tournament updates, room details and results will show up here." />
            )}
          </View>
        }
      />
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.xs },
  skeletons: { gap: SPACE.xs },
  card: { ...glassPanel(c), flexDirection: 'row', alignItems: 'flex-start', gap: SPACE.sm, borderRadius: RADIUS.lg, padding: SPACE.sm },
  unread: { backgroundColor: c.brandSoft, borderColor: c.borderStrong },
  icon: { width: 36, height: 36, borderRadius: RADIUS.sm, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' },
  iconUnread: { backgroundColor: c.surface3 },
  iconRead: { opacity: 0.6 },
  text: { flex: 1, minWidth: 0, gap: 2 },
  titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: SPACE.xs },
  title: { ...TYPE.bodyStrong, color: c.fg2, flex: 1 },
  titleUnread: { color: c.fg },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: c.brandText, marginTop: 6 },
  body: { ...TYPE.body, color: c.fg2 },
  time: { ...TYPE.meta, color: c.fg3, marginTop: 2 },
}));
