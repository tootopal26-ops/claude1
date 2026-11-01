import { apiRequest } from '../api/client';
import type { ExchangeHistoryItem, ExchangeTier, SpinStatus, TokenBalance } from './types';

export function fetchSpinStatus() {
  return apiRequest<{ data: SpinStatus }>('/spin');
}

export function fetchTokenBalance() {
  return apiRequest<{ data: TokenBalance }>('/tokens');
}

export function fetchExchangeTiers() {
  return apiRequest<{ data: ExchangeTier[] }>('/exchange/tiers');
}

export function fetchExchangeHistory() {
  return apiRequest<{ data: ExchangeHistoryItem[] }>('/exchange/history');
}
