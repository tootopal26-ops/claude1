/** The spin wheel's 8 physical segments, clockwise from the pointer at the top (0°). Fixed
 *  on the server (SpinService::SEGMENTS) — the app only animates to whatever the server says. */
export type SpinSegment = { number: number; angle: number };

export type SpinStatus = {
  enabled: boolean;
  /** Spin is on AND a rewarded ad is actually available right now. */
  available: boolean;
  ads_available: boolean;
  balance: number;
  segments: SpinSegment[];
};

export type TokenBalance = {
  balance: number;
};

export type ExchangeTier = {
  index: number;
  tokens: number;
  coins: string;
  bonus_percent: string;
};

export type ExchangeHistoryItem = {
  id: number;
  tier_index: number;
  tokens_required: number;
  coins_received: string;
  bonus_percent: string;
  status: 'credited' | 'failed';
  failure_reason: string | null;
  created_at: string;
};
