import { useCallback, useRef, useState } from 'react';

import { useAds } from '../../ads/AdsContext';
import { useSound } from '../../sound/SoundContext';
import { fetchTournament } from '../../api/games';
import type { Tournament } from '../../types';
import { canJoin, needsTickets, ticketsEarned, ticketsRequired } from './tournamentState';

export type TicketNotice = { tournamentId: number; tone: 'success' | 'info' | 'warning' | 'danger'; text: string };

/**
 * Join for a tournament that may need rewarded-ad tickets. Each tap of Join Now:
 *  1. re-reads the tournament from the server (so an ad that finished while the app was
 *     away, or a closed or full tournament, is seen before anything else is offered);
 *  2. if tickets are still missing, plays exactly one rewarded ad — the server adds the
 *     ticket only from the ad network's verified callback — then re-reads the count;
 *  3. once every ticket is held, opens the normal join flow. Reaching 3/3 never joins by
 *     itself: the join is still the player's action, and the server re-checks every rule.
 */
export function useTicketedJoin(openJoin: (tournament: Tournament) => void, onUpdated: (tournament: Tournament) => void) {
  const { watchRewarded } = useAds();
  const sound = useSound();
  const [earningFor, setEarningFor] = useState<number | null>(null);
  const [notice, setNotice] = useState<TicketNotice | null>(null);
  const running = useRef(false);

  const join = useCallback(
    async (tournament: Tournament) => {
      if (running.current) return;
      if (ticketsRequired(tournament) === 0) {
        openJoin(tournament);
        return;
      }

      running.current = true;
      setEarningFor(tournament.id);
      setNotice(null);
      const say = (tone: TicketNotice['tone'], text: string) => setNotice({ tournamentId: tournament.id, tone, text });

      try {
        let fresh: Tournament;
        try {
          fresh = (await fetchTournament(tournament.id)).data;
          onUpdated(fresh);
        } catch {
          say('danger', "Couldn't check this tournament. Check your connection and try again.");
          return;
        }

        if (fresh.already_joined) return;
        if (!canJoin(fresh)) {
          say('warning', 'Joining is closed for this tournament.');
          return;
        }
        if (!needsTickets(fresh)) {
          openJoin(fresh);
          return;
        }

        const outcome = await watchRewarded({ purpose: 'tournament_ticket', tournamentId: fresh.id });

        try {
          const after = (await fetchTournament(fresh.id)).data;
          onUpdated(after);
          if (outcome.status === 'verified' && outcome.claim.outcome === null) {
            const earned = ticketsEarned(after);
            const required = ticketsRequired(after);
            sound.playUnlock();
            say('success', earned >= required ? 'All tickets earned. Tap Join to enter.' : `Ticket ${earned}/${required} earned.`);
            return;
          }
        } catch {
          // The count stays as last seen; the next tap re-reads it before offering another ad.
        }

        if (outcome.status !== 'verified' && outcome.status !== 'pending') sound.playWarning();
        say(outcome.status === 'verified' || outcome.status === 'pending' ? 'info' : 'warning', outcome.message);
      } finally {
        running.current = false;
        setEarningFor(null);
      }
    },
    [openJoin, onUpdated, watchRewarded, sound]
  );

  return { join, earningFor, notice, clearNotice: () => setNotice(null) };
}
