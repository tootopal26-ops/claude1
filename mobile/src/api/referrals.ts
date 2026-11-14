import { apiRequest } from './client';

export type ReferralMe = {
  enabled: boolean;
  referral_code: string;
  successful_referrals: number;
  total_coins_earned: string;
  /** The live admin-configured amount the REFERRER earns per successful referral —
   *  decimal string, never computed on-device. */
  referrer_reward: string;
  /** The live admin-configured amount the NEW PLAYER receives for using a code. */
  new_player_reward: string;
};

export type ReferralHistoryItem = {
  id: number;
  referred_username: string | null;
  referrer_reward: string;
  status: 'completed' | 'failed';
  created_at: string;
};

export function fetchReferralMe() {
  return apiRequest<{ data: ReferralMe }>('/referrals/me');
}

export function fetchReferralHistory() {
  return apiRequest<{ data: ReferralHistoryItem[] }>('/referrals/history');
}
