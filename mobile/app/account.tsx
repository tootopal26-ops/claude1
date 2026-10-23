import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '../src/api/client';
import { fetchSessions, revokeSession } from '../src/api/profile';
import { useAuth } from '../src/auth/AuthContext';
import { AmbientGlow } from '../src/components/AmbientGlow';
import { Avatar } from '../src/components/avatar/Avatar';
import { Button } from '../src/components/Button';
import { Notice } from '../src/components/Notice';
import { Sheet } from '../src/components/sheet/Sheet';
import { ErrorState, Skeleton } from '../src/components/states/States';
import { StatusChip } from '../src/components/StatusChip';
import { glassPanel } from '../src/theme/glass';
import { Icon } from '../src/theme/icons';
import { makeStyles, useTheme } from '../src/theme/ThemeContext';
import { MAX_CONTENT_WIDTH, RADIUS, SIZE, SPACE, TYPE } from '../src/theme/tokens';
import type { Session } from '../src/types';
import { formatDate, formatDateTime } from '../src/utils/time';
import { useRetained } from '../src/utils/useRetained';

/**
 * My Profile: the account details the server holds for this player (read-only — there is no
 * edit endpoint) and the devices signed in to it, each of which can be signed out.
 */
export default function AccountScreen() {
  const { user } = useAuth();
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const contentWidth = Math.min(width, MAX_CONTENT_WIDTH) - SPACE.md * 2;
  const [sessions, setSessions] = useState<Session[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [revoking, setRevoking] = useState<Session | null>(null);
  const [busy, setBusy] = useState(false);
  const [revokeError, setRevokeError] = useState<string | null>(null);
  const shownRevoking = useRetained(revoking);

  const load = useCallback(async () => {
    try {
      const response = await fetchSessions();
      setSessions(response.data);
      setFailed(false);
    } catch {
      setFailed(true);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (!user) return null;

  const details: { label: string; value: string | null | undefined }[] = [
    { label: 'Username', value: user.username },
    { label: 'Mobile number', value: user.mobile },
    { label: 'Email', value: user.email },
    { label: 'State', value: user.state },
    { label: 'Member since', value: user.member_since ? formatDate(user.member_since) : null },
  ];

  return (
    <View style={styles.screen}>
      <AmbientGlow />
      <ScrollView
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
      >
        <View style={[styles.column, { width: contentWidth }]}>
          <View style={styles.identity}>
            <Avatar username={user.username} size={SIZE.avatarPodium} ring="home" />
            <View style={styles.identityText}>
              <Text style={styles.name} numberOfLines={1}>
                {user.username}
              </Text>
              <Text style={styles.meta}>Profile pictures are not available yet.</Text>
            </View>
          </View>

          <View style={styles.panel}>
            {details
              .filter((detail) => detail.value)
              .map((detail, index, list) => (
                <View key={detail.label} style={[styles.detail, index < list.length - 1 && styles.divider]}>
                  <Text style={styles.detailLabel}>{detail.label}</Text>
                  <Text style={styles.detailValue} selectable numberOfLines={1}>
                    {detail.value}
                  </Text>
                </View>
              ))}
          </View>

          <Text style={styles.sectionTitle} accessibilityRole="header">
            Signed-in devices
          </Text>
          {failed && !sessions ? (
            <ErrorState compact title="Couldn't load your devices" onRetry={() => load()} />
          ) : !sessions ? (
            <Skeleton height={120} radius={RADIUS.lg} />
          ) : (
            <View style={styles.panel}>
              {sessions.map((session, index) => (
                <View key={session.id} style={[styles.session, index < sessions.length - 1 && styles.divider]}>
                  <View style={styles.sessionIcon}>
                    <Icon name="user" size={18} color={colors.fg2} />
                  </View>
                  <View style={styles.sessionText}>
                    <Text style={styles.detailValue} numberOfLines={1}>
                      {session.device_name || 'Unnamed device'}
                    </Text>
                    <Text style={styles.meta}>{session.last_used_at ? `Last active ${formatDateTime(session.last_used_at)}` : `Signed in ${formatDateTime(session.created_at)}`}</Text>
                  </View>
                  {session.is_current ? (
                    <StatusChip tone="success" label="This device" />
                  ) : (
                    <Button
                      label="Sign out"
                      size="sm"
                      variant="secondary"
                      onPress={() => {
                        setRevokeError(null);
                        setRevoking(session);
                      }}
                      accessibilityLabel={`Sign out ${session.device_name || 'this device'}`}
                    />
                  )}
                </View>
              ))}
            </View>
          )}
        </View>
      </ScrollView>

      <Sheet
        visible={revoking !== null}
        onClose={() => setRevoking(null)}
        title="Sign out device?"
        subtitle={`${shownRevoking?.device_name || 'The device'} will need the password to sign in again.`}
        dismissible={!busy}
      >
        {revokeError ? <Notice tone="danger">{revokeError}</Notice> : null}
        <Button
          label="Sign out device"
          variant="danger"
          loading={busy}
          onPress={async () => {
            if (!revoking) return;
            setBusy(true);
            try {
              await revokeSession(revoking.id);
              setRevoking(null);
              await load();
            } catch (error) {
              setRevokeError(error instanceof ApiError ? error.message : "We couldn't reach Mini Max. Try again.");
            } finally {
              setBusy(false);
            }
          }}
        />
        <Button label="Cancel" variant="secondary" onPress={() => setRevoking(null)} disabled={busy} />
      </Sheet>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  screen: { flex: 1 },
  content: { alignItems: 'center', paddingTop: SPACE.md },
  column: { gap: SPACE.md },
  identity: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm },
  identityText: { flex: 1, minWidth: 0, gap: 2 },
  name: { ...TYPE.screenTitle, color: c.fg },
  meta: { ...TYPE.meta, color: c.fg3 },
  panel: { ...glassPanel(c), borderRadius: RADIUS.lg, overflow: 'hidden' },
  detail: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.sm, minHeight: 52, paddingHorizontal: SPACE.md },
  divider: { borderBottomWidth: 1, borderBottomColor: c.border },
  detailLabel: { ...TYPE.body, color: c.fg3 },
  detailValue: { ...TYPE.bodyStrong, color: c.fg, flexShrink: 1, textAlign: 'right' },
  sectionTitle: { ...TYPE.section, color: c.fg, marginTop: SPACE.xs },
  session: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, minHeight: 64, paddingHorizontal: SPACE.md, paddingVertical: SPACE.xs },
  sessionIcon: { width: 36, height: 36, borderRadius: RADIUS.sm, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' },
  sessionText: { flex: 1, minWidth: 0, gap: 2 },
}));
