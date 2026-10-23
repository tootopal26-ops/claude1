import { apiRequest } from './client';
import type { Leaderboard, LeaderboardBanner } from '../types';

export function fetchLeaderboard(type: Leaderboard['type']) {
  return apiRequest<{ data: Leaderboard }>(`/leaderboards/${type}`);
}

export function fetchLeaderboardBanners() {
  return apiRequest<{ data: LeaderboardBanner[] }>('/leaderboards/banners');
}
