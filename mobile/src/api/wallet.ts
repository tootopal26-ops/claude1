import { apiRequest } from './client';
import type { Wallet, WalletLedgerEntry } from '../types';

export function fetchWallet() {
  return apiRequest<{ data: Wallet }>('/wallet');
}

type LedgerResponse = {
  data: WalletLedgerEntry[];
  meta: { current_page: number; last_page: number };
};

export function fetchWalletLedger(page = 1) {
  return apiRequest<LedgerResponse>(`/wallet/ledger?page=${page}`);
}
