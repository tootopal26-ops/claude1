export type User = {
  id: number;
  username: string;
  mobile: string;
};

export type Tournament = {
  id: number;
  title: string;
  description: string | null;
  rules: string | null;
  mode: 'solo' | 'duo' | 'squad';
  required_player_count: number;
  entry_fee: string;
  per_kill_reward: string;
  prize_pool: string;
  total_slots: number;
  joined_slots: number;
  scheduled_start_at: string;
  status: 'upcoming' | 'ongoing' | 'completed' | 'cancelled';
  credentials_released: boolean;
  already_joined: boolean;
  my_result: { rank: number; kills: number; prize_awarded: string } | null;
};

export type TournamentEntryPlayer = {
  slot_number: number;
  in_game_name: string;
  uid: string | null;
};

export type TournamentEntry = {
  id: number;
  tournament_id: number;
  entry_fee_paid: string;
  players: TournamentEntryPlayer[];
};

export type TournamentCredentials = {
  room_id: string;
  room_password: string;
  released_at: string;
};

export type WithdrawalRequest = {
  id: number;
  amount: string;
  upi_id: string;
  status: 'pending' | 'approved' | 'processing' | 'paid' | 'rejected' | 'failed';
  rejection_reason: string | null;
  failure_reason: string | null;
  settlement_reference: string | null;
  created_at: string;
};

export type AppNotification = {
  id: number;
  title: string;
  body: string;
  data: { deep_link?: string; tournament_id?: string } & Record<string, unknown>;
  read: boolean;
  created_at: string;
};

export type Wallet = {
  deposit_balance: string;
  eligible_winnings_balance: string;
  total_balance: string;
  withdrawable_balance: string;
};

export type WalletLedgerEntry = {
  id: number;
  type: string;
  balance: 'deposit' | 'winnings';
  amount: string;
  balance_after: string;
  reason: string | null;
  created_at: string;
};
