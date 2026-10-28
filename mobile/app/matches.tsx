import { useLocalSearchParams } from 'expo-router';

import { parseTab, TournamentListView } from '../src/components/tournament/TournamentListView';

/**
 * Profile > Total Matches: every tournament the player has joined, on the same Upcoming /
 * Ongoing / Completed tabs as the main list (the server filters with `joined=1`, so the
 * tab counts are the player's own). Completed is the default — the matches already played.
 */
export default function MatchesScreen() {
  const { status } = useLocalSearchParams<{ status?: string }>();

  return <TournamentListView joinedOnly initialTab={status ? parseTab(status) : 'completed'} />;
}
