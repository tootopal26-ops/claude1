import { apiRequest } from '../api/client';
import type { AdEvent, AdRewardCampaign, AdsClientConfig, AdsEarningHistoryItem, AdsEarningStatus, DailyRewardStatus, RewardedClaim } from './types';

export function fetchAdsConfig() {
  return apiRequest<{ data: AdsClientConfig }>('/ads/config');
}

export function sendAdEvents(events: AdEvent[]) {
  return apiRequest<{ data: { recorded: number } }>('/ads/events', { method: 'POST', body: { events } });
}

export function openRewardedClaim(body: { purpose: 'tournament_ticket' | 'daily_reward' | 'ads_earning' | 'campaign'; tournament_id?: number; campaign_id?: number }) {
  return apiRequest<{ data: RewardedClaim }>('/ads/claims', { method: 'POST', body });
}

export function fetchRewardedClaim(token: string) {
  return apiRequest<{ data: RewardedClaim }>(`/ads/claims/${token}`);
}

export function cancelRewardedClaim(token: string) {
  return apiRequest<{ data: RewardedClaim }>(`/ads/claims/${token}/cancel`, { method: 'POST' });
}

/** Local QA only — the server refuses it in production and unless explicitly allowed. */
export function simulateRewardedClaim(token: string) {
  return apiRequest<{ data: RewardedClaim }>(`/ads/claims/${token}/simulate`, { method: 'POST' });
}

export function attemptWithdrawalPlacement(withdrawalId: number) {
  return apiRequest<{ data: { show: boolean } }>(`/ads/withdrawals/${withdrawalId}/post-success`, { method: 'POST' });
}

export function fetchDailyReward() {
  return apiRequest<{ data: DailyRewardStatus }>('/daily-reward');
}

export function dismissDailyReward() {
  return apiRequest<{ data: DailyRewardStatus }>('/daily-reward/dismiss', { method: 'POST' });
}

export function claimDailyRewardWithoutAd() {
  return apiRequest<{ data: { amount: string; status: DailyRewardStatus } }>('/daily-reward/claim', { method: 'POST' });
}

export function fetchAdsEarning() {
  return apiRequest<{ data: AdsEarningStatus }>('/ads-earning');
}

export function setAdsEarningPreference(enabled: boolean) {
  return apiRequest<{ data: AdsEarningStatus }>('/ads-earning/preference', { method: 'POST', body: { enabled } });
}

export function fetchAdsEarningHistory(page = 1) {
  return apiRequest<{ data: AdsEarningHistoryItem[]; meta: { current_page: number; last_page: number; total: number } }>(`/ads-earning/history?page=${page}`);
}

export function fetchAdCampaigns() {
  return apiRequest<{ data: AdRewardCampaign[] }>('/ads/campaigns');
}
