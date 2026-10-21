import { apiRequest } from './client';
import type { DepositOrder } from '../types';

type DepositsResponse = {
  data: DepositOrder[];
  meta: { current_page: number; last_page: number };
};

export function fetchDeposits(page = 1) {
  return apiRequest<DepositsResponse>(`/deposits?page=${page}`);
}

export function requestDeposit(amount: string) {
  return apiRequest<{ data: DepositOrder }>('/deposits', {
    method: 'POST',
    body: { amount },
  });
}

export function fetchDeposit(id: number) {
  return apiRequest<{ data: DepositOrder }>(`/deposits/${id}`);
}
