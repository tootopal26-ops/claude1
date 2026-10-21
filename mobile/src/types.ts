export type User = {
  id: number;
  username: string;
  mobile: string;
};

export type Tournament = {
  id: number;
  title: string;
  mode: 'solo' | 'duo' | 'squad';
  entry_fee: string;
  total_slots: number;
  joined_slots: number;
  scheduled_start_at: string;
  status: 'upcoming' | 'ongoing' | 'completed' | 'cancelled';
  already_joined: boolean;
};

export type TournamentEntry = {
  id: number;
  tournament_id: number;
  in_game_name: string;
  uid: string | null;
};
