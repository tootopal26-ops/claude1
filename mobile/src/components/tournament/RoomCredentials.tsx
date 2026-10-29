import * as Clipboard from 'expo-clipboard';
import { useEffect, useRef, useState } from 'react';
import { Animated, Text, View } from 'react-native';

import { glassPanel } from '../../theme/glass';
import { useSoundOptional } from '../../sound/SoundContext';
import { DURATION, EASE, PRESS_SCALE } from '../../theme/motion';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../../theme/tokens';
import type { TournamentCredentials } from '../../types';
import { formatDateTime } from '../../utils/time';
import { PressableScale } from '../interaction/PressableScale';

type Props =
  | { state: 'released'; credentials: TournamentCredentials }
  | { state: 'waiting' }
  | { state: 'error'; onRetry: () => void };

/**
 * The room ID and password, shown only to players with a confirmed entry and only once
 * staff have released them (the server enforces both). Each value has its own Copy button
 * that confirms with "Copied". Before release it says so plainly instead of showing blanks.
 */
export function RoomCredentials(props: Props) {
  const styles = useStyles();
  const { colors } = useTheme();

  return (
    <View style={styles.card}>
      <View style={styles.head}>
        <View style={styles.headIcon}>
          <Icon name={props.state === 'released' ? 'unlock' : 'lock'} size={20} />
        </View>
        <Text style={styles.title} accessibilityRole="header">
          Room details
        </Text>
      </View>
      {props.state === 'released' ? (
        <>
          <CopyRow label="Room ID" value={props.credentials.room_id} />
          <CopyRow label="Password" value={props.credentials.room_password} />
          <Text style={styles.meta}>Released {formatDateTime(props.credentials.released_at)}. Keep them to yourself.</Text>
        </>
      ) : props.state === 'waiting' ? (
        <Text style={styles.body}>Staff release the room ID and password shortly before the start. They will appear here, and you will get a notification.</Text>
      ) : (
        <View style={styles.errorRow}>
          <Text style={[styles.body, styles.flex]}>We couldn&apos;t load the room details.</Text>
          <PressableScale onPress={props.onRetry} accessibilityRole="button" accessibilityLabel="Retry loading room details" scaleTo={PRESS_SCALE.button}>
            <View style={styles.copy}>
              <Icon name="refresh" size={16} color={colors.fg} />
              <Text style={styles.copyText}>Retry</Text>
            </View>
          </PressableScale>
        </View>
      )}
    </View>
  );
}

function CopyRow({ label, value }: { label: string; value: string }) {
  const styles = useStyles();
  const { semanticColors } = useTheme();
  const [copied, setCopied] = useState(false);
  const confirm = useRef(new Animated.Value(0)).current;
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const sound = useSoundOptional();
  const copy = async () => {
    try {
      await Clipboard.setStringAsync(value);
    } catch {
      return;
    }
    setCopied(true);
    sound.playSuccess();
    confirm.setValue(0);
    Animated.timing(confirm, { toValue: 1, duration: DURATION.small, easing: EASE.out, useNativeDriver: true }).start();
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => setCopied(false), 1600);
  };

  return (
    <View style={styles.row}>
      <View style={styles.flex}>
        <Text style={styles.label}>{label}</Text>
        <Text style={styles.value} selectable>
          {value}
        </Text>
      </View>
      <PressableScale onPress={copy} accessibilityRole="button" accessibilityLabel={copied ? `${label} copied` : `Copy ${label}`} scaleTo={PRESS_SCALE.button}>
        <View style={[styles.copy, copied && styles.copied]}>
          {copied ? (
            <Animated.View style={[styles.copyInner, { opacity: confirm }]}>
              <Icon name="check" size={16} color={semanticColors.success} />
              <Text style={[styles.copyText, { color: semanticColors.success }]}>Copied</Text>
            </Animated.View>
          ) : (
            <View style={styles.copyInner}>
              <Icon name="copy" size={18} />
              <Text style={styles.copyText}>Copy</Text>
            </View>
          )}
        </View>
      </PressableScale>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  card: { ...glassPanel(c), borderRadius: RADIUS.lg, padding: SPACE.md, gap: SPACE.sm },
  head: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
  headIcon: { width: 32, height: 32, borderRadius: RADIUS.sm, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' },
  title: { ...TYPE.cardTitle, color: c.fg },
  body: { ...TYPE.body, color: c.fg2 },
  meta: { ...TYPE.meta, color: c.fg3 },
  flex: { flex: 1, minWidth: 0 },
  errorRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: SPACE.sm,
    padding: SPACE.sm,
    borderRadius: RADIUS.md,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.border,
  },
  label: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.4 },
  value: { ...TYPE.section, color: c.fg, letterSpacing: 0.5 },
  copy: {
    minHeight: SIZE.buttonSm,
    minWidth: 92,
    paddingHorizontal: SPACE.sm,
    borderRadius: RADIUS.md,
    backgroundColor: c.surface3,
    borderWidth: 1,
    borderColor: c.borderStrong,
    alignItems: 'center',
    justifyContent: 'center',
  },
  copied: { borderColor: s.success, backgroundColor: s.successSoft },
  copyInner: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  copyText: { ...TYPE.bodyStrong, fontSize: 13, color: c.fg },
}));
