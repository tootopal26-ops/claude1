import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { Redirect, router } from 'expo-router';

import { fetchNotifications, markNotificationRead } from '../src/api/notifications';
import { useAuth } from '../src/auth/AuthContext';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import type { AppNotification } from '../src/types';

export default function NotificationsScreen() {
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const response = await fetchNotifications();
      setNotifications(response.data);
    } catch {
      setError('Could not load notifications. Pull to retry.');
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    (async () => {
      setLoading(true);
      await load();
      setLoading(false);
    })();
  }, [user, load]);

  if (authLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  if (!user) {
    return <Redirect href="/login" />;
  }

  async function handlePress(notification: AppNotification) {
    if (!notification.read) {
      setNotifications((current) =>
        current.map((item) => (item.id === notification.id ? { ...item, read: true } : item))
      );
      markNotificationRead(notification.id).catch(() => {
        // Non-fatal: the row still shows as read locally; next refresh will reconcile.
      });
    }

    const tournamentId = notification.data?.tournament_id;
    if (tournamentId) {
      router.push(`/tournament/${tournamentId}`);
    }
  }

  return (
    <View style={styles.container}>
      {loading ? (
        <ActivityIndicator style={styles.centered} color={colors.brandText} />
      ) : (
        <FlatList
          data={notifications}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.listContent}
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
          ListEmptyComponent={<Text style={styles.empty}>{error ?? 'No notifications yet.'}</Text>}
          renderItem={({ item }) => (
            <Pressable style={[styles.card, !item.read && styles.cardUnread]} onPress={() => handlePress(item)}>
              {!item.read && <View style={styles.unreadDot} />}
              <Text style={styles.cardTitle}>{item.title}</Text>
              <Text style={styles.cardBody}>{item.body}</Text>
            </Pressable>
          )}
        />
      )}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  container: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  listContent: { padding: 16, gap: 12 },
  empty: { color: c.fg2, textAlign: 'center', marginTop: 32 },
  card: {
    backgroundColor: c.surface,
    borderRadius: 16,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: c.border,
  },
  cardUnread: {
    backgroundColor: c.brandSoft,
    borderColor: c.borderStrong,
  },
  unreadDot: {
    position: 'absolute',
    top: 14,
    right: 14,
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: c.brand,
  },
  cardTitle: { color: c.fg, fontSize: 15, fontWeight: '700', marginBottom: 4, paddingRight: 16 },
  cardBody: { color: c.fg2, fontSize: 13, lineHeight: 18 },
}));
