import { useState } from 'react';
import { Image, Text, View } from 'react-native';

import { glassPanel } from '../../theme/glass';
import { Icon, type IconName } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { Tournament } from '../../types';
import { formatDateTime } from '../../utils/time';
import { Button } from '../Button';
import { CoinAmount } from '../CoinAmount';
import { PressableScale } from '../interaction/PressableScale';
import { StatusChip } from '../StatusChip';
import { cardAction, isFree, MODE_LABELS, statusChip } from './tournamentState';

type Props = {
  tournament: Tournament;
  onOpen: (tournament: Tournament) => void;
  onJoin: (tournament: Tournament) => void;
  onRules: (tournament: Tournament) => void;
  onResults: (tournament: Tournament) => void;
};

const THUMB_WIDTH = 104;

/**
 * One tournament, the same everywhere it is listed: the game's 16:9 artwork, title, start
 * time, mode and status; prize pool, per-kill reward and entry fee; how full it is; and two
 * actions — the rules (only when the tournament has any) and the one next step its real
 * state allows (Join, Joined, Room details, Results, or a disabled Full / Closed). The
 * card itself opens the details. Fields the backend does not have (map, booyah reward)
 * are not shown.
 */
export function TournamentCard({ tournament, onOpen, onJoin, onRules, onResults }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const [imageFailed, setImageFailed] = useState(false);
  const picture = tournament.game?.thumbnail_url ?? tournament.game?.image_url ?? null;
  const fill = tournament.total_slots > 0 ? Math.min(1, tournament.joined_slots / tournament.total_slots) : 0;
  const chip = statusChip(tournament);
  const action = cardAction(tournament);
  const hasRules = Boolean(tournament.rules && tournament.rules.trim().length > 0);

  const onAction = () => {
    if (action.kind === 'join') onJoin(tournament);
    else if (action.kind === 'results') onResults(tournament);
    else if (action.kind === 'open') onOpen(tournament);
  };

  // The details area and the two action buttons are siblings, never nested controls.
  return (
    <View style={styles.card}>
      <PressableScale onPress={() => onOpen(tournament)} accessibilityRole="button" accessibilityHint="Opens the tournament details" style={styles.body}>
        <View style={styles.top}>
          <View style={styles.thumb}>
            {picture && !imageFailed ? (
              <Image source={{ uri: picture }} style={styles.thumbImage} resizeMode="cover" onError={() => setImageFailed(true)} />
            ) : (
              <Icon name="tournaments" size={22} color={colors.fg3} />
            )}
          </View>
          <View style={styles.heading}>
            <Text style={styles.title} numberOfLines={2}>
              {tournament.title}
            </Text>
            <View style={styles.when}>
              <Icon name="calendar" size={13} color={colors.fg3} />
              <Text style={styles.whenText} numberOfLines={1}>
                {formatDateTime(tournament.scheduled_start_at)}
              </Text>
            </View>
            <View style={styles.chips}>
              <View style={styles.mode}>
                <Icon name="users" size={12} color={colors.fg2} />
                <Text style={styles.modeText}>{MODE_LABELS[tournament.mode]}</Text>
              </View>
              <StatusChip tone={chip.tone} label={chip.label} />
            </View>
          </View>
        </View>

        <View style={styles.stats}>
          <Stat label="Prize pool" value={tournament.prize_pool} />
          <Stat label="Per kill" value={tournament.per_kill_reward} />
          <Stat label="Entry" value={isFree(tournament) ? null : tournament.entry_fee} />
        </View>

        <View style={styles.slots}>
          <View style={styles.slotsHead}>
            <Text style={styles.slotsText}>
              {tournament.joined_slots}/{tournament.total_slots} {tournament.mode === 'solo' ? 'players' : 'teams'}
            </Text>
            <Text style={styles.slotsText}>{Math.max(0, tournament.total_slots - tournament.joined_slots)} left</Text>
          </View>
          <View style={styles.track} accessibilityRole="progressbar" accessibilityLabel="Slots filled" aria-valuemin={0} aria-valuemax={tournament.total_slots} aria-valuenow={tournament.joined_slots}>
            <View style={[styles.fill, { width: `${fill * 100}%` }]} />
          </View>
        </View>
      </PressableScale>

      <View style={styles.actions}>
        {hasRules ? <Button label="Rules" variant="secondary" size="sm" icon="file-text" onPress={() => onRules(tournament)} style={styles.actionButton} /> : null}
        <Button
          label={action.label}
          size="sm"
          icon={actionIcon(action.kind, action.label)}
          variant={action.kind === 'join' || (action.kind === 'open' && action.primary) ? 'primary' : 'secondary'}
          disabled={action.kind === 'disabled'}
          onPress={onAction}
          style={styles.actionButton}
          accessibilityLabel={`${action.label}, ${tournament.title}`}
        />
      </View>
    </View>
  );
}

function actionIcon(kind: string, label: string): IconName | undefined {
  if (kind === 'results') return 'trophy';
  if (label === 'Joined') return 'check-circle';
  if (label === 'Room details') return 'lock';
  return undefined;
}

function Stat({ label, value }: { label: string; value: string | null }) {
  const styles = useStyles();

  return (
    <View style={styles.stat}>
      <Text style={styles.statLabel} numberOfLines={1}>
        {label}
      </Text>
      {value === null ? (
        <Text style={styles.free}>Free</Text>
      ) : (
        <CoinAmount value={value} size={14} textStyle={styles.statValue} style={styles.statAmount} />
      )}
    </View>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  card: { ...glassPanel(c), borderRadius: RADIUS.lg, padding: SPACE.sm, gap: SPACE.sm },
  body: { gap: SPACE.sm },
  top: { flexDirection: 'row', gap: SPACE.sm },
  thumb: {
    width: THUMB_WIDTH,
    height: Math.round((THUMB_WIDTH * 9) / 16),
    borderRadius: RADIUS.sm,
    overflow: 'hidden',
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  thumbImage: { width: '100%', height: '100%' },
  heading: { flex: 1, minWidth: 0, gap: 4 },
  title: { ...TYPE.cardTitle, color: c.fg },
  when: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  whenText: { ...TYPE.meta, color: c.fg2, flexShrink: 1 },
  chips: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6 },
  mode: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: RADIUS.pill,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.border,
  },
  modeText: { ...TYPE.label, color: c.fg2 },
  stats: { flexDirection: 'row', gap: SPACE.xs },
  stat: { flex: 1, minWidth: 0, backgroundColor: c.surface2, borderRadius: RADIUS.sm, paddingVertical: SPACE.xs, paddingHorizontal: SPACE.xs, gap: 2 },
  statLabel: { ...TYPE.label, color: c.fg3, textTransform: 'uppercase', letterSpacing: 0.4 },
  statAmount: { gap: 4 },
  statValue: { ...TYPE.bodyStrong, color: c.fg },
  free: { ...TYPE.bodyStrong, color: s.success },
  slots: { gap: 6 },
  slotsHead: { flexDirection: 'row', justifyContent: 'space-between' },
  slotsText: { ...TYPE.meta, color: c.fg2 },
  track: { height: 6, borderRadius: 3, backgroundColor: c.surface3, overflow: 'hidden' },
  fill: { height: 6, borderRadius: 3, backgroundColor: c.brand },
  actions: { flexDirection: 'row', gap: SPACE.xs },
  actionButton: { flex: 1 },
}));
