import { apiRequest } from './client';

export type AppConfig = {
  /** Admin > Customer Support; null until an Admin sets one. */
  support_url: string | null;
};

export function fetchAppConfig() {
  return apiRequest<{ data: AppConfig }>('/app-config');
}
