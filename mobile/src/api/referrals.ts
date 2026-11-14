import { apiRequest } from './client';

export type ReferralMe = {
  enabled: boolean;
  referral_code: string;
  successful_referrals: number;
  total_coins_earned: string;
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
