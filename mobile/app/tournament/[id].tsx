import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { Redirect, useLocalSearchParams } from 'expo-router';

import { ApiError, apiRequest } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import type { Tournament, TournamentEntry } from '../../src/types';

export default function TournamentDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { user, isLoading: authLoading } = useAuth();

  const [tournament, setTournament] = useState<Tournament | null>(null);
  const [loading, setLoading] = useState(true);
  const [inGameName, setInGameName] = useState('');
  const [uid, setUid] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [joinedEntry, setJoinedEntry] = useState<TournamentEntry | null>(null);

  const loadTournament = useCallback(async () => {
    try {
      const response = await apiRequest<{ data: Tournament }>(`/tournaments/${id}`);
      setTournament(response.data);
    } catch {
      setError('Could not load this tournament.');
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

  async function handleJoin() {
    setError(null);
    setSubmitting(true);
    try {
      const response = await apiRequest<{ data: TournamentEntry }>(`/tournaments/${id}/join`, {
        method: 'POST',
        body: { in_game_name: inGameName, uid: uid || null },
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
  const canJoin = tournament.status === 'upcoming' && !alreadyJoined && !isFull;

  return (
    <View style={styles.container}>
      <Text style={styles.title}>{tournament.title}</Text>
      <Text style={styles.meta}>
        {tournament.mode.toUpperCase()} · {tournament.joined_slots}/{tournament.total_slots} joined
      </Text>
      <Text style={styles.meta}>
        {Number(tournament.entry_fee) === 0 ? 'Free entry' : `Entry fee: ${tournament.entry_fee} coins`}
      </Text>

      {alreadyJoined ? (
        <View style={styles.confirmation}>
          <Text style={styles.confirmationTitle}>You're confirmed for this tournament</Text>
          {joinedEntry && <Text style={styles.confirmationMeta}>IGN: {joinedEntry.in_game_name}</Text>}
        </View>
      ) : canJoin ? (
        <View style={styles.form}>
          <TextInput
            style={styles.input}
            placeholder="In-game name"
            maxLength={20}
            value={inGameName}
            onChangeText={setInGameName}
          />
          <TextInput
            style={styles.input}
            placeholder="UID (optional)"
            maxLength={12}
            keyboardType="number-pad"
            value={uid}
            onChangeText={setUid}
          />
          {error && <Text style={styles.error}>{error}</Text>}
          <Pressable
            style={[styles.button, !inGameName && styles.buttonDisabled]}
            onPress={handleJoin}
            disabled={submitting || !inGameName}
          >
            {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Join tournament</Text>}
          </Pressable>
        </View>
      ) : (
        <Text style={styles.error}>{isFull ? 'This tournament is full.' : 'Joining is closed for this tournament.'}</Text>
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
});
