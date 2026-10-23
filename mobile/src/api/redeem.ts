import { apiRequest } from './client';
import type { RedeemResult } from '../types';

/** The server decides the reward and the new balance; the app only sends the code. */
export function redeemCode(code: string) {
  return apiRequest<RedeemResult>('/redeem', { method: 'POST', body: { code } });
}
