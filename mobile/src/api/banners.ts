import { apiRequest } from './client';
import type { LeaderboardBanner } from '../types';

/** The Home hero carousel, as configured in Admin > Home Banners. */
export function fetchHomeBanners() {
  return apiRequest<{ data: LeaderboardBanner[] }>('/banners/home');
}
