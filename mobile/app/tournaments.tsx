import { useLocalSearchParams } from 'expo-router';

import { parseTab, TournamentListView } from '../src/components/tournament/TournamentListView';

/** All tournaments, opened on the tab the Home shortcut asked for (Upcoming by default). */
export default function TournamentsScreen() {
  const { status } = useLocalSearchParams<{ status?: string }>();

  return <TournamentListView initialTab={parseTab(status)} />;
}
