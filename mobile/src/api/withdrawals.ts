import { apiRequest } from './client';
import type { WithdrawalRequest } from '../types';

type WithdrawalsResponse = {
  data: WithdrawalRequest[];
  meta: { current_page: number; last_page: number };
};

export function fetchWithdrawals(page = 1) {
  return apiRequest<WithdrawalsResponse>(`/withdrawals?page=${page}`);
}

export function requestWithdrawal(amount: string, upiId: string) {
  return apiRequest<{ data: WithdrawalRequest }>('/withdrawals', {
    method: 'POST',
    body: { amount, upi_id: upiId },
  });
}
