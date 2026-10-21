import { apiRequest } from './client';
import type { AppNotification } from '../types';

type NotificationsResponse = {
  data: AppNotification[];
  meta: { unread_count: number; current_page: number; last_page: number };
};

export function fetchNotifications(page = 1) {
  return apiRequest<NotificationsResponse>(`/notifications?page=${page}`);
}

export function markNotificationRead(id: number) {
  return apiRequest<{ data: AppNotification }>(`/notifications/${id}/read`, { method: 'POST' });
}

export function registerPushToken(token: string, platform: 'android' | 'ios', deviceName?: string) {
  return apiRequest<{ data: { message: string } }>('/devices/push-token', {
    method: 'POST',
    body: { token, platform, device_name: deviceName },
  });
}

export function deregisterPushToken(token: string) {
  return apiRequest<{ data: { message: string } }>('/devices/push-token', {
    method: 'DELETE',
    body: { token },
  });
}
