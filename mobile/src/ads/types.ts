/** Placements are a fixed catalog on the server; Admin switches each on or off. */
export type AdPlacementKey =
  | 'home_hero'
  | 'home_between'
  | 'home_game_card'
  | 'app_open'
  | 'tournament_ticket'
  | 'rewarded_general'
  | 'ads_earning'
  | 'redeem_verification'
  | 'daily_login'
  | 'withdrawal_success'
  | 'leaderboard_feed'
  | 'tournament_feed'
  | 'wallet_profile';

export type AdFormat = 'rewarded' | 'interstitial' | 'banner' | 'native' | 'app_open';

export type AdsClientConfig = {
  enabled: boolean;
  rewarded_enabled: boolean;
  test_mode: boolean;
  levelplay_app_key: string | null;
  simulation_allowed: boolean;
  app_open: { cooldown_minutes: number; session_cap: number };
  placements: {
    key: AdPlacementKey;
    format: AdFormat;
    ad_unit_id: string;
    daily_cap: number | null;
    cooldown_seconds: number | null;
    insert_every: number | null;
  }[];
};

export type RewardedPurpose = 'tournament_ticket' | 'daily_reward' | 'ads_earning' | 'campaign' | 'redeem_code';

export type RewardedClaim = {
  token: string;
  dynamic_user_id: string;
  purpose: RewardedPurpose;
  placement: AdPlacementKey;
  ad_unit_id: string | null;
  status: 'pending' | 'verified' | 'rejected' | 'expired' | 'cancelled';
  expires_at: string;
  reward_coins: string | null;
  counts_for_earning: boolean | null;
  /** Why a verified claim granted nothing (e.g. the tournament closed meanwhile). */
  outcome: string | null;
  progress: { required: number; earned: number } | { units_today: number; daily_limit: number } | null;
};

/** What the ad SDK reports back after showing a rewarded ad. Only the server decides rewards. */
export type RewardedShowResult = 'rewarded' | 'closed' | 'failed' | 'unavailable';

export type AdEventName =
  | 'init_success'
  | 'init_failed'
  | 'requested'
  | 'loaded'
  | 'no_fill'
  | 'load_failed'
  | 'impression'
  | 'display_failed'
  | 'clicked'
  | 'closed'
  | 'rewarded_client';

export type AdEvent = {
  placement: string;
  event: AdEventName;
  ad_network?: string | null;
  ad_unit_id?: string | null;
  revenue_usd?: number | null;
  precision?: string | null;
  error_code?: string | null;
  is_test?: boolean;
  occurred_at?: string;
};

export type DailyRewardStatus = {
  day: string;
  enabled: boolean;
  eligibility: Eligibility;
  requires_ad: boolean;
  ads_available: boolean;
  claimed_today: boolean;
  claimed_amount: string | null;
  dismissed_today: boolean;
  available: boolean;
  show_popup: boolean;
  range: { min: string; max: string };
  title: string;
  description: string;
};

export type Eligibility = { total: string; required: string; remaining: string; eligible: boolean };

export type AdsEarningStatus = {
  feature_enabled: boolean;
  paused: boolean;
  available: boolean;
  can_toggle: boolean;
  opted_in: boolean;
  eligibility: Eligibility;
  period: { start: string; end: string };
  units_this_period: number;
  units_today: number;
  daily_limit: number;
  pending_coins: string;
  credited_coins: string;
  last_settlement_at: string | null;
  explanation: string;
};

export type AdsEarningHistoryItem = {
  id: number;
  period_start: string;
  period_end: string;
  units: number;
  coins: string;
  status: 'pending' | 'processing' | 'credited' | 'adjusted' | 'below_minimum';
  credited_at: string | null;
  reference: string | null;
  /** Audited corrections Mini Max applied after crediting, each with its own ledger entry. */
  adjustments?: { coins: string; reason: string; at: string; reference: string }[];
};

/** A fixed-reward campaign: the stated coins for one verified rewarded ad, up to a daily limit. */
export type AdRewardCampaign = {
  id: number;
  name: string;
  reward_coins: string;
  daily_limit: number;
  available: boolean;
  reason: string | null;
};
