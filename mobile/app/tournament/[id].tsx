import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, ScrollView, Text, View } from 'react-native';
import { Redirect, useLocalSearchParams } from 'expo-router';

import { ApiError, apiRequest } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import { Button } from '../../src/components/Button';
import { CoinAmount } from '../../src/components/CoinAmount';
import { Notice } from '../../src/components/Notice';
import { StatusChip } from '../../src/components/StatusChip';
import { TextField } from '../../src/components/TextField';
import { makeStyles, useTheme } from '../../src/theme/ThemeContext';
import { useWallet } from '../../src/wallet/WalletContext';
import type { Tournament, TournamentCredentials, TournamentEntry } from '../../src/types';

const PLAYER_LABELS = ['Player 1 (you)', 'Player 2', 'Player 3', 'Player 4'];

type PlayerInput = { inGameName: string; uid: string };

export default function TournamentDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { user, isLoading: authLoading } = useAuth();
  const { colors } = useTheme();
  const styles = useStyles();
  const { refresh: refreshWallet } = useWallet();

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
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  if (!user) {
    return <Redirect href="/login" />;
  }

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.brandText} />
      </View>
    );
  }

  if (!tournament) {
    return (
      <View style={styles.centered}>
        <Notice tone="danger">{error ?? 'Tournament not found.'}</Notice>
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
      refreshWallet({ force: true }); // the entry fee was just charged
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
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>{tournament.title}</Text>
      <Text style={styles.meta}>
        {tournament.mode.toUpperCase()} · {tournament.joined_slots}/{tournament.total_slots} joined
      </Text>
      <View style={styles.feeRow}>
        {Number(tournament.entry_fee) === 0 ? (
          <StatusChip tone="success" label="Free entry" />
        ) : (
          <>
            <Text style={styles.meta}>Entry fee:</Text>
            <CoinAmount value={tournament.entry_fee} suffix="coins" />
          </>
        )}
      </View>

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
            <View style={styles.prizeRow}>
              <Text style={styles.prizeLabel}>Prize awarded:</Text>
              <CoinAmount value={tournament.my_result.prize_awarded} suffix="coins" />
            </View>
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
              <TextField
                label="In-game name"
                placeholder="In-game name"
                maxLength={20}
                value={player.inGameName}
                onChangeText={(value) => updatePlayer(index, 'inGameName', value)}
              />
              <TextField
                label="UID (optional)"
                placeholder="UID (optional)"
                maxLength={12}
                keyboardType="number-pad"
                value={player.uid}
                onChangeText={(value) => updatePlayer(index, 'uid', value)}
              />
            </View>
          ))}
          {error && <Notice tone="danger">{error}</Notice>}
          <Button label="Join tournament" onPress={handleJoin} loading={submitting} disabled={!allFilled} style={styles.joinButton} />
        </View>
      ) : (
        <View style={styles.closedNotice}>
          <Notice tone="warning">
            {isFull
              ? 'This tournament is full.'
              : tournament.status === 'cancelled'
                ? 'This tournament was cancelled.'
                : 'Joining is closed for this tournament.'}
          </Notice>
        </View>
      )}
    </ScrollView>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  container: { flex: 1 },
  content: { padding: 20, paddingBottom: 40 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  title: { color: c.fg, fontSize: 24, fontWeight: '700', marginBottom: 8 },
  meta: { color: c.fg2, fontSize: 14 },
  feeRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 8 },
  form: { marginTop: 24, gap: 16 },
  playerGroup: {
    gap: 10,
    backgroundColor: c.surface,
    borderWidth: 1,
    borderColor: c.border,
    borderRadius: 16,
    padding: 14,
  },
  playerLabel: { color: c.fg, fontSize: 14, fontWeight: '700' },
  joinButton: { marginTop: 4 },
  closedNotice: { marginTop: 24 },
  confirmation: {
    marginTop: 24,
    backgroundColor: s.successSoft,
    borderRadius: 16,
    padding: 16,
  },
  confirmationTitle: { color: s.success, fontWeight: '700', fontSize: 16 },
  confirmationMeta: { color: c.fg2, marginTop: 4 },
  credentials: {
    marginTop: 16,
    backgroundColor: c.brandSoft,
    borderRadius: 16,
    padding: 16,
    borderWidth: 1,
    borderColor: c.borderStrong,
  },
  credentialsTitle: { color: c.fg, fontWeight: '700', fontSize: 16 },
  credentialsMeta: { color: c.fg2, marginTop: 4, fontSize: 14 },
  result: {
    marginTop: 16,
    backgroundColor: s.warningSoft,
    borderRadius: 16,
    padding: 16,
  },
  resultTitle: { color: s.warning, fontWeight: '700', fontSize: 16 },
  resultMeta: { color: c.fg, marginTop: 4, fontSize: 14 },
  prizeLabel: { color: c.fg, fontSize: 14 },
  prizeRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 4 },
}));
