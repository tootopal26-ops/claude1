import { apiRequest } from './client';
import type { Leaderboard } from '../types';

export function fetchLeaderboard(type: Leaderboard['type']) {
  return apiRequest<{ data: Leaderboard }>(`/leaderboards/${type}`);
}
