import type { ChipTone } from '../StatusChip';
import type { Tournament } from '../../types';
import { timeUntil } from '../../utils/time';

export const MODE_LABELS: Record<Tournament['mode'], string> = { solo: 'Solo', duo: 'Duo', squad: 'Squad' };

export function isFree(tournament: Tournament): boolean {
  return Number(tournament.entry_fee) === 0;
}

export function isFull(tournament: Tournament): boolean {
  return tournament.joined_slots >= tournament.total_slots;
}

/**
 * Whether a join can be offered, as the server reports it (`joining_open`: upcoming, before
 * the cut-off, credentials not out, a slot free). The server re-checks every rule on submit.
 */
export function canJoin(tournament: Tournament): boolean {
  if (tournament.already_joined) return false;
  if (typeof tournament.joining_open === 'boolean') return tournament.joining_open;

  return tournament.status === 'upcoming' && !tournament.credentials_released && !isFull(tournament);
}

export function statusChip(tournament: Tournament, now: number = Date.now()): { label: string; tone: ChipTone } {
  switch (tournament.status) {
    case 'ongoing':
      return { label: 'Live', tone: 'success' };
    case 'completed':
      return { label: 'Completed', tone: 'neutral' };
    case 'cancelled':
      return { label: 'Cancelled', tone: 'danger' };
    default: {
      const until = timeUntil(tournament.scheduled_start_at, now);
      return { label: until ? `Starts ${until}` : 'Starting', tone: 'info' };
    }
  }
}

export type CardAction =
  | { kind: 'join'; label: string }
  | { kind: 'open'; label: string; primary: boolean }
  | { kind: 'results'; label: string }
  | { kind: 'disabled'; label: string };

/** The one action a tournament card offers, from the tournament's real state. */
export function cardAction(tournament: Tournament): CardAction {
  if (tournament.status === 'completed') return { kind: 'results', label: 'Results' };
  if (tournament.status === 'cancelled') return { kind: 'open', label: 'Details', primary: false };
  if (tournament.status === 'ongoing') {
    return tournament.already_joined ? { kind: 'open', label: 'Room details', primary: true } : { kind: 'open', label: 'Details', primary: false };
  }
  if (tournament.already_joined) return { kind: 'open', label: 'Joined', primary: false };
  if (canJoin(tournament)) return { kind: 'join', label: 'Join' };

  return { kind: 'disabled', label: isFull(tournament) ? 'Full' : 'Closed' };
}

/** Rewarded-ad tickets this tournament needs before the player can join (free tournaments only). */
export function ticketsRequired(tournament: Tournament): number {
  return isFree(tournament) ? Math.max(0, tournament.ad_tickets_required ?? 0) : 0;
}

export function ticketsEarned(tournament: Tournament): number {
  return Math.min(ticketsRequired(tournament), Math.max(0, tournament.ad_tickets_earned ?? 0));
}

/** True while the player still has tickets to earn before Join opens the normal join flow. */
export function needsTickets(tournament: Tournament): boolean {
  return ticketsRequired(tournament) > 0 && ticketsEarned(tournament) < ticketsRequired(tournament);
}
