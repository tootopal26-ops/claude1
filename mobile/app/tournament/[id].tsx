import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { Redirect, useLocalSearchParams } from 'expo-router';

import { ApiError, apiRequest } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import type { Tournament, TournamentCredentials, TournamentEntry } from '../../src/types';

const PLAYER_LABELS = ['Player 1 (you)', 'Player 2', 'Player 3', 'Player 4'];

type PlayerInput = { inGameName: string; uid: string };

export default function TournamentDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { user, isLoading: authLoading } = useAuth();

  const [tournament, setTournament] = useState<Tournament | null>(null);
  const [loading, setLoading] = useState(true);
  const [players, setPlayers] = useState<PlayerInput[]>([{ inGameName: '', uid: '' }]);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [joinedEntry, setJoinedEntry] = useState<TournamentEntry | null>(null);
  const [credentials, setCredentials] = useState<TournamentCredentials | null>(null);

  const loadTournament = useCallback(async () => {
    try {
      const response = await apiRequest<{ data: Tournament }>(`/tournaments/${id}`);
      setTournament(response.data);
      setPlayers((current) => {
        const requiredCount = response.data.required_player_count;
        if (current.length === requiredCount) return current;
        return Array.from({ length: requiredCount }, (_, index) => current[index] ?? { inGameName: '', uid: '' });
      });
    } catch {
      setError('Could not load this tournament.');
    }
  }, [id]);

  const loadCredentialsIfAvailable = useCallback(async () => {
    try {
      const response = await apiRequest<{ data: TournamentCredentials }>(`/tournaments/${id}/credentials`);
      setCredentials(response.data);
    } catch {
      // Not joined yet, or not released yet — the UI simply omits this section.
    }
  }, [id]);

  useEffect(() => {
    if (!user) return;
    (async () => {
      setLoading(true);
      await loadTournament();
      setLoading(false);
    })();
  }, [user, loadTournament]);

  useEffect(() => {
    if (tournament?.credentials_released && (tournament.already_joined || joinedEntry)) {
      loadCredentialsIfAvailable();
    }
  }, [tournament?.credentials_released, tournament?.already_joined, joinedEntry, loadCredentialsIfAvailable]);

  if (authLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator />
      </View>
    );
  }

  if (!user) {
    return <Redirect href="/login" />;
  }

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator />
      </View>
    );
  }

  if (!tournament) {
    return (
      <View style={styles.centered}>
        <Text style={styles.error}>{error ?? 'Tournament not found.'}</Text>
      </View>
    );
  }

  function updatePlayer(index: number, field: 'inGameName' | 'uid', value: string) {
    setPlayers((current) => current.map((player, i) => (i === index ? { ...player, [field]: value } : player)));
  }

  async function handleJoin() {
    setError(null);
    setSubmitting(true);
    try {
      const response = await apiRequest<{ data: TournamentEntry }>(`/tournaments/${id}/join`, {
        method: 'POST',
        body: {
          players: players.map((player) => ({ in_game_name: player.inGameName, uid: player.uid || null })),
        },
      });
      setJoinedEntry(response.data);
      await loadTournament();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not join this tournament.');
    } finally {
      setSubmitting(false);
    }
  }

  const alreadyJoined = tournament.already_joined || joinedEntry !== null;
  const isFull = tournament.joined_slots >= tournament.total_slots;
  const canJoin = tournament.status === 'upcoming' && !alreadyJoined && !isFull && !tournament.credentials_released;
  const allFilled = players.every((player) => player.inGameName.trim().length > 0);

  return (
    <View style={styles.container}>
      <Text style={styles.title}>{tournament.title}</Text>
      <Text style={styles.meta}>
        {tournament.mode.toUpperCase()} · {tournament.joined_slots}/{tournament.total_slots} joined
      </Text>
      <Text style={styles.meta}>
        {Number(tournament.entry_fee) === 0 ? 'Free entry' : `Entry fee: ${tournament.entry_fee} coins`}
      </Text>

      {credentials && (
        <View style={styles.credentials}>
          <Text style={styles.credentialsTitle}>Room details</Text>
          <Text style={styles.credentialsMeta}>Room ID: {credentials.room_id}</Text>
          <Text style={styles.credentialsMeta}>Password: {credentials.room_password}</Text>
        </View>
      )}

      {tournament.my_result && (
        <View style={styles.result}>
          <Text style={styles.resultTitle}>Your result</Text>
          <Text style={styles.resultMeta}>Rank #{tournament.my_result.rank} · {tournament.my_result.kills} kills</Text>
          {Number(tournament.my_result.prize_awarded) > 0 && (
            <Text style={styles.resultMeta}>Prize awarded: {tournament.my_result.prize_awarded} coins</Text>
          )}
        </View>
      )}

      {alreadyJoined ? (
        <View style={styles.confirmation}>
          <Text style={styles.confirmationTitle}>You're confirmed for this tournament</Text>
          {joinedEntry?.players.map((player) => (
            <Text key={player.slot_number} style={styles.confirmationMeta}>
              {PLAYER_LABELS[player.slot_number - 1] ?? `Player ${player.slot_number}`}: {player.in_game_name}
            </Text>
          ))}
          {!credentials && tournament.status !== 'cancelled' && !tournament.my_result && (
            <Text style={styles.confirmationMeta}>Room details will appear here once they're released.</Text>
          )}
        </View>
      ) : canJoin ? (
        <View style={styles.form}>
          {players.map((player, index) => (
            <View key={index} style={styles.playerGroup}>
              <Text style={styles.playerLabel}>{PLAYER_LABELS[index] ?? `Player ${index + 1}`}</Text>
              <TextInput
                style={styles.input}
                placeholder="In-game name"
                maxLength={20}
                value={player.inGameName}
                onChangeText={(value) => updatePlayer(index, 'inGameName', value)}
              />
              <TextInput
                style={styles.input}
                placeholder="UID (optional)"
                maxLength={12}
                keyboardType="number-pad"
                value={player.uid}
                onChangeText={(value) => updatePlayer(index, 'uid', value)}
              />
            </View>
          ))}
          {error && <Text style={styles.error}>{error}</Text>}
          <Pressable
            style={[styles.button, !allFilled && styles.buttonDisabled]}
            onPress={handleJoin}
            disabled={submitting || !allFilled}
          >
            {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Join tournament</Text>}
          </Pressable>
        </View>
      ) : (
        <Text style={styles.error}>
          {isFull
            ? 'This tournament is full.'
            : tournament.status === 'cancelled'
              ? 'This tournament was cancelled.'
              : 'Joining is closed for this tournament.'}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#1a1025', padding: 20 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#1a1025' },
  title: { color: '#fff', fontSize: 24, fontWeight: '700', marginBottom: 8 },
  meta: { color: '#C4B5FD', fontSize: 14, marginBottom: 4 },
  form: { marginTop: 24, gap: 12 },
  playerGroup: { gap: 8, marginBottom: 4 },
  playerLabel: { color: '#C4B5FD', fontSize: 13, fontWeight: '600' },
  input: {
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: 12,
    paddingHorizontal: 16,
    paddingVertical: 12,
    color: '#fff',
  },
  button: {
    backgroundColor: '#8B5CF6',
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: { opacity: 0.5 },
  buttonText: { color: '#fff', fontWeight: '600', fontSize: 16 },
  error: { color: '#F87171', marginTop: 8 },
  confirmation: {
    marginTop: 24,
    backgroundColor: 'rgba(52,211,153,0.15)',
    borderRadius: 16,
    padding: 16,
  },
  confirmationTitle: { color: '#34D399', fontWeight: '700', fontSize: 16 },
  confirmationMeta: { color: '#C4B5FD', marginTop: 4 },
  credentials: {
    marginTop: 16,
    backgroundColor: 'rgba(139,92,246,0.15)',
    borderRadius: 16,
    padding: 16,
    borderWidth: 1,
    borderColor: 'rgba(139,92,246,0.4)',
  },
  credentialsTitle: { color: '#fff', fontWeight: '700', fontSize: 16 },
  credentialsMeta: { color: '#C4B5FD', marginTop: 4, fontSize: 14 },
  result: {
    marginTop: 16,
    backgroundColor: 'rgba(250,204,21,0.12)',
    borderRadius: 16,
    padding: 16,
    borderWidth: 1,
    borderColor: 'rgba(250,204,21,0.35)',
  },
  resultTitle: { color: '#FACC15', fontWeight: '700', fontSize: 16 },
  resultMeta: { color: '#fff', marginTop: 4, fontSize: 14 },
});
