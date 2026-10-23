import { apiRequest } from './client';
import type { Game, Tournament } from '../types';

export function fetchGames() {
  return apiRequest<{ data: Game[] }>('/games');
}

export function fetchTournaments(gameId?: number) {
  return apiRequest<{ data: Tournament[] }>(gameId ? `/tournaments?game_id=${gameId}` : '/tournaments');
}
