import { apiRequest } from './client';
import type { Game, Tournament, TournamentCredentials, TournamentEntry, TournamentResults, TournamentTab } from '../types';

export function fetchGames() {
  return apiRequest<{ data: Game[] }>('/games');
}

export type TournamentListResponse = {
  data: Tournament[];
  /** Size of every tab for the same game filter. */
  meta: { counts: Record<TournamentTab, number> };
};

export function fetchTournaments(options: { gameId?: number; status?: TournamentTab; joined?: boolean } = {}) {
  const params = new URLSearchParams();
  if (options.gameId) params.set('game_id', String(options.gameId));
  if (options.status) params.set('status', options.status);
  if (options.joined) params.set('joined', '1');
  const query = params.toString();

  return apiRequest<TournamentListResponse>(query ? `/tournaments?${query}` : '/tournaments');
}

export function fetchTournament(id: number | string) {
  return apiRequest<{ data: Tournament }>(`/tournaments/${id}`);
}

export function fetchTournamentResults(id: number | string) {
  return apiRequest<{ data: TournamentResults }>(`/tournaments/${id}/results`);
}

export function joinTournament(id: number, players: { in_game_name: string; uid: string | null }[]) {
  return apiRequest<{ data: TournamentEntry }>(`/tournaments/${id}/join`, { method: 'POST', body: { players } });
}

/** Room ID and password: only for players with a confirmed entry, once staff have released them. */
export function fetchCredentials(id: number | string) {
  return apiRequest<{ data: TournamentCredentials }>(`/tournaments/${id}/credentials`);
}
