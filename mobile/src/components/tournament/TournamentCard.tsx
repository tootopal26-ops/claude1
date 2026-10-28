import { useState } from 'react';
import { Image, Text, View } from 'react-native';

import { Artwork, ARTWORK_SIZE, type ArtworkName } from '../../theme/artwork';
import { glassPanel } from '../../theme/glass';
import { Icon, type IconName } from '../../theme/icons';
import { makeStyles } from '../../theme/ThemeContext';
import { ASPECT, RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { Tournament } from '../../types';
import { formatDateTime } from '../../utils/time';
import { Button } from '../Button';
import { PressableScale } from '../interaction/PressableScale';
import { StatusChip } from '../StatusChip';
import { Notice } from '../Notice';
import { TicketProgress } from './TicketProgress';
import { TournamentMetric } from './TournamentMetric';
import { cardAction, isFree, MODE_LABELS, needsTickets, statusChip, ticketsEarned, ticketsRequired } from './tournamentState';

type Props = {
  tournament: Tournament;
  onOpen: (tournament: Tournament) => void;
  onJoin: (tournament: Tournament) => void;
  onRules: (tournament: Tournament) => void;
  onResults: (tournament: Tournament) => void;
  /** A rewarded ad for this tournament's next ticket is playing or being verified. */
  earningTicket?: boolean;
  /** The outcome of the last ticket ad for this tournament, from the server. */
  ticketNotice?: { tone: 'success' | 'info' | 'warning' | 'danger'; text: string } | null;
};

const MODE_ARTWORK: Record<Tournament['mode'], ArtworkName> = { solo: 'modeSolo', duo: 'modeDuo', squad: 'modeSquad' };

/**
 * One tournament, the same everywhere it is listed (Upcoming, Ongoing, Completed, a game's
 * list and My matches). Top to bottom: the game's artwork as a large 16:9 picture across the
 * full width of the card; then the title, start time (supplied calendar icon), mode
 * (supplied Solo / Duo / Squad icon) and status; the supplied Prize Pool / Kill / Entry Fee
 * badges with the tournament's own amounts (the badges carry the words, so there are no
 * separate text labels);
 * how full it is; and two actions — the rules (only when the tournament has any) and the one
 * next step its real state allows (Join, Joined, Room details, Results, or a disabled
 * Full / Closed). The card itself opens the details. Fields the backend does not have (map,
 * booyah reward) are not shown.
 */
export function TournamentCard({ tournament, onOpen, onJoin, onRules, onResults, earningTicket, ticketNotice }: Props) {
  const styles = useStyles();
  const [imageFailed, setImageFailed] = useState(false);
  // The full 16:9 artwork first; the smaller thumbnail only if that is all the game has.
  const picture = tournament.game?.image_url ?? tournament.game?.thumbnail_url ?? null;
  const fill = tournament.total_slots > 0 ? Math.min(1, tournament.joined_slots / tournament.total_slots) : 0;
  const chip = statusChip(tournament);
  const action = cardAction(tournament);
  const hasRules = Boolean(tournament.rules && tournament.rules.trim().length > 0);
  const tickets = ticketsRequired(tournament);
  const showTickets = tickets > 0 && tournament.status === 'upcoming' && !tournament.already_joined;
  const locked = action.kind === 'join' && needsTickets(tournament);

  const onAction = () => {
    if (action.kind === 'join') onJoin(tournament);
    else if (action.kind === 'results') onResults(tournament);
    else if (action.kind === 'open') onOpen(tournament);
  };

  // The details area and the two action buttons are siblings, never nested controls.
  return (
    <View style={styles.card}>
      <PressableScale onPress={() => onOpen(tournament)} accessibilityRole="button" accessibilityHint="Opens the tournament details" style={styles.body}>
        <View style={styles.hero}>
          {picture && !imageFailed ? (
            <Image source={{ uri: picture }} style={styles.heroImage} resizeMode="cover" onError={() => setImageFailed(true)} accessibilityIgnoresInvertColors />
          ) : (
            <View style={styles.heroPlaceholder}>
              <Icon name="tournaments" size={32} />
            </View>
          )}
        </View>

        <View style={styles.details}>
          <Text style={styles.title} numberOfLines={2}>
            {tournament.title}
          </Text>
          <View style={styles.when}>
            <Artwork name="calendar" size={ARTWORK_SIZE.inline} />
            <Text style={styles.whenText} numberOfLines={1}>
              {formatDateTime(tournament.scheduled_start_at)}
            </Text>
          </View>
          <View style={styles.chips}>
            <View style={styles.mode}>
              <Artwork name={MODE_ARTWORK[tournament.mode]} size={ARTWORK_SIZE.inline} />
              <Text style={styles.modeText}>{MODE_LABELS[tournament.mode]}</Text>
            </View>
            <StatusChip tone={chip.tone} label={chip.label} />
          </View>
        </View>

        <View style={styles.stats}>
          <TournamentMetric kind="prizePool" value={tournament.prize_pool} badgeSize={ARTWORK_SIZE.metric} style={styles.stat} />
          <TournamentMetric kind="perKill" value={tournament.per_kill_reward} badgeSize={ARTWORK_SIZE.metric} style={styles.stat} />
          <TournamentMetric kind="entryFee" value={isFree(tournament) ? null : tournament.entry_fee} badgeSize={ARTWORK_SIZE.metric} style={styles.stat} />
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

      {showTickets ? (
        <View style={styles.tickets}>
          <TicketProgress earned={ticketsEarned(tournament)} required={tickets} />
        </View>
      ) : null}
      {ticketNotice ? (
        <View style={styles.tickets}>
          <Notice tone={ticketNotice.tone}>{ticketNotice.text}</Notice>
        </View>
      ) : null}

      <View style={styles.actions}>
        {hasRules ? <Button label="Rules" variant="secondary" size="sm" icon="file-text" onPress={() => onRules(tournament)} style={styles.actionButton} /> : null}
        <Button
          label={locked ? 'Join Now' : action.label}
          size="sm"
          icon={locked ? 'lock' : actionIcon(action.kind, action.label)}
          loading={locked && earningTicket}
          accessibilityHint={locked ? 'Plays one rewarded ad for your next ticket' : undefined}
          variant={action.kind === 'join' || (action.kind === 'open' && action.primary) ? 'primary' : 'secondary'}
          disabled={action.kind === 'disabled'}
          onPress={onAction}
          style={styles.actionButton}
          accessibilityLabel={`${locked ? 'Join Now' : action.label}, ${tournament.title}`}
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

const useStyles = makeStyles(({ colors: c }) => ({
  // The picture runs edge to edge across the top; everything below it is padded.
  card: { ...glassPanel(c), borderRadius: RADIUS.lg, overflow: 'hidden', paddingBottom: SPACE.sm, gap: SPACE.sm },
  body: { gap: SPACE.sm },
  hero: { width: '100%', aspectRatio: ASPECT.tournament, backgroundColor: c.surface2, borderBottomWidth: 1, borderBottomColor: c.border },
  heroImage: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, width: '100%', height: '100%' },
  heroPlaceholder: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  details: { gap: 6, paddingHorizontal: SPACE.sm },
  title: { ...TYPE.cardTitle, color: c.fg },
  when: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  whenText: { ...TYPE.meta, color: c.fg2, flexShrink: 1 },
  chips: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6 },
  mode: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingLeft: 4,
    paddingRight: 8,
    paddingVertical: 3,
    borderRadius: RADIUS.pill,
    backgroundColor: c.surface2,
    borderWidth: 1,
    borderColor: c.border,
  },
  modeText: { ...TYPE.label, color: c.fg2 },
  stats: { flexDirection: 'row', gap: SPACE.xs, paddingHorizontal: SPACE.sm },
  stat: { flex: 1, minWidth: 0, backgroundColor: c.surface2, borderRadius: RADIUS.sm, paddingVertical: SPACE.xs, paddingHorizontal: 4 },
  slots: { gap: 6, paddingHorizontal: SPACE.sm },
  slotsHead: { flexDirection: 'row', justifyContent: 'space-between' },
  slotsText: { ...TYPE.meta, color: c.fg2 },
  track: { height: 6, borderRadius: 3, backgroundColor: c.surface3, overflow: 'hidden' },
  fill: { height: 6, borderRadius: 3, backgroundColor: c.brand },
  tickets: { paddingHorizontal: SPACE.sm },
  actions: { flexDirection: 'row', gap: SPACE.xs, paddingHorizontal: SPACE.sm },
  actionButton: { flex: 1 },
}));
