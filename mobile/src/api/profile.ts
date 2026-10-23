import { apiRequest } from './client';
import type { ProfileStats, Session } from '../types';

export function fetchProfileStats() {
  return apiRequest<{ data: ProfileStats }>('/profile/stats');
}

export function fetchSessions() {
  return apiRequest<{ data: Session[] }>('/sessions');
}

export function revokeSession(id: number) {
  return apiRequest<{ data: unknown }>(`/sessions/${id}`, { method: 'DELETE' });
}
