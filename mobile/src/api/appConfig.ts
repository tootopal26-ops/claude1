import { apiRequest } from './client';

export type DepositBonusConfig = {
  enabled: boolean;
  /** Decimal string, e.g. "50.00" — never computed on-device. */
  amount: string;
  min_deposit: string;
  /** Best-effort display only — the backend's own locked check when a deposit is
   *  confirmed is the only thing that actually decides whether a bonus is credited. */
  eligible: boolean;
};

export type AppConfig = {
  /** Admin > Customer Support; null until an Admin sets one. */
  support_url: string | null;
  deposit_bonus: DepositBonusConfig;
};

export function fetchAppConfig() {
  return apiRequest<{ data: AppConfig }>('/app-config');
}
