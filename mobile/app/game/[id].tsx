import { Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';

import { fetchGames } from '../../src/api/games';
import { parseTab, TournamentListView } from '../../src/components/tournament/TournamentListView';
import type { Game } from '../../src/types';

/** One game's tournaments — the destination of a Home game card — filtered by its ID. */
export default function GameTournamentsScreen() {
  const { id, status } = useLocalSearchParams<{ id: string; status?: string }>();
  const gameId = Number(id);
  const [game, setGame] = useState<Game | null>(null);

  useEffect(() => {
    let active = true;
    fetchGames()
      .then((response) => {
        if (active) setGame(response.data.find((candidate) => candidate.id === gameId) ?? null);
      })
      .catch(() => {});

    return () => {
      active = false;
    };
  }, [gameId]);

  return (
    <>
      <Stack.Screen options={{ title: game?.title ?? 'Tournaments' }} />
      <TournamentListView gameId={gameId} initialTab={parseTab(status)} intro={game?.description} />
    </>
  );
}
