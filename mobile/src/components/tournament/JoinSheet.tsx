import { useEffect, useRef, useState } from 'react';
import { Text, View } from 'react-native';

import { ApiError } from '../../api/client';
import { joinTournament } from '../../api/games';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../../theme/tokens';
import type { Tournament, TournamentEntry } from '../../types';
import { formatCoins } from '../../utils/coins';
import { pushOnce } from '../../utils/navigation';
import { formatDateTime } from '../../utils/time';
import { useRetained } from '../../utils/useRetained';
import { useWallet } from '../../wallet/WalletContext';
import { Button } from '../Button';
import { CoinAmount } from '../CoinAmount';
import { Notice } from '../Notice';
import { Sheet } from '../sheet/Sheet';
import { TextField } from '../TextField';
import { isFree, MODE_LABELS } from './tournamentState';

type Player = { inGameName: string; uid: string };
type FieldErrors = Record<string, string>;

type Step =
  | { name: 'players' }
  | { name: 'review'; error: string | null; insufficient: boolean }
  | { name: 'submitting' }
  | { name: 'done'; entry: TournamentEntry };

type Props = {
  tournament: Tournament | null;
  onClose: () => void;
  /** Called once the server has confirmed the entry (to reload slots and state). */
  onJoined: (entry: TournamentEntry) => void;
  /** Hide "View tournament" on the success step when already on the details screen. */
  onDetailsScreen?: boolean;
};

const UNKNOWN_OUTCOME =
  "We couldn't confirm whether you joined. Check the tournament before trying again — joining twice is refused, so you are never charged twice.";

/**
 * Joining, in three steps: the players' in-game names (as many as the mode needs), a review
 * that shows the entry fee beside the current balance, and the server's answer. Nothing is
 * charged before the player confirms; the request is sent once (a second tap is ignored),
 * the sheet cannot be dismissed while it runs, and success is shown only from the server's
 * response — then the shared wallet and the tournament are refreshed.
 */
export function JoinSheet({ tournament: requested, onClose, onJoined, onDetailsScreen }: Props) {
  const styles = useStyles();
  const { semanticColors } = useTheme();
  const { wallet, refresh: refreshWallet } = useWallet();
  const tournament = useRetained(requested);
  const [players, setPlayers] = useState<Player[]>([]);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [step, setStep] = useState<Step>({ name: 'players' });
  const sending = useRef(false);

  // Each open starts clean, sized for the mode, with a fresh balance for the review.
  useEffect(() => {
    if (!requested) return;
    setPlayers(Array.from({ length: requested.required_player_count }, () => ({ inGameName: '', uid: '' })));
    setFieldErrors({});
    setStep({ name: 'players' });
    void refreshWallet({ force: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requested?.id]);

  if (!tournament) return null;

  const fee = Number(tournament.entry_fee);
  const free = isFree(tournament);
  const balance = wallet ? Number(wallet.total_balance) : null;
  const short = !free && balance !== null && balance < fee;
  const submitting = step.name === 'submitting';

  const update = (index: number, field: keyof Player, value: string) => {
    setPlayers((current) => current.map((player, i) => (i === index ? { ...player, [field]: field === 'uid' ? value.replace(/\D/g, '') : value } : player)));
    setFieldErrors((current) => {
      const key = `players.${index}.${field === 'inGameName' ? 'in_game_name' : 'uid'}`;
      if (!current[key]) return current;
      const next = { ...current };
      delete next[key];
      return next;
    });
  };

  const toReview = () => {
    const errors: FieldErrors = {};
    players.forEach((player, index) => {
      if (player.inGameName.trim().length === 0) errors[`players.${index}.in_game_name`] = 'Enter the in-game name.';
    });
    setFieldErrors(errors);
    if (Object.keys(errors).length === 0) setStep({ name: 'review', error: null, insufficient: false });
  };

  const submit = async () => {
    if (sending.current) return;
    sending.current = true;
    setStep({ name: 'submitting' });
    try {
      const response = await joinTournament(tournament.id, players.map((player) => ({ in_game_name: player.inGameName.trim(), uid: player.uid || null })));
      setStep({ name: 'done', entry: response.data });
      void refreshWallet({ force: true });
      onJoined(response.data);
    } catch (error) {
      if (error instanceof ApiError) {
        const perField: FieldErrors = {};
        Object.entries(error.errors ?? {}).forEach(([key, messages]) => {
          if (key.startsWith('players.')) perField[key] = messages[0];
        });
        if (Object.keys(perField).length > 0) {
          setFieldErrors(perField);
          setStep({ name: 'players' });
        } else {
          setStep({ name: 'review', error: error.message, insufficient: /insufficient/i.test(error.message) });
        }
        void refreshWallet({ force: true });
      } else {
        setStep({ name: 'review', error: UNKNOWN_OUTCOME, insufficient: false });
      }
    } finally {
      sending.current = false;
    }
  };

  const title = step.name === 'done' ? "You're in" : step.name === 'players' ? 'Join tournament' : 'Review and confirm';
  const subtitle = step.name === 'done' ? tournament.title : `${tournament.title} · ${MODE_LABELS[tournament.mode]}`;

  return (
    <Sheet visible={requested !== null} onClose={onClose} title={title} subtitle={subtitle} variant="bottom" dismissible={!submitting}>
      {step.name === 'players' && (
        <>
          <Text style={styles.help}>
            {tournament.required_player_count === 1
              ? 'Enter your in-game name exactly as it appears in the game.'
              : `This is a ${MODE_LABELS[tournament.mode].toLowerCase()} tournament: enter all ${tournament.required_player_count} players, starting with you.`}
          </Text>
          {players.map((player, index) => (
            <View key={index} style={styles.player}>
              <Text style={styles.playerLabel}>{index === 0 ? 'Player 1 (you)' : `Player ${index + 1}`}</Text>
              <TextField
                label="In-game name (required)"
                placeholder="Name in the game"
                maxLength={20}
                autoCapitalize="none"
                autoCorrect={false}
                value={player.inGameName}
                onChangeText={(value) => update(index, 'inGameName', value)}
                errorText={fieldErrors[`players.${index}.in_game_name`]}
                returnKeyType="next"
              />
              <TextField
                label="Game UID (optional)"
                placeholder="Numbers only"
                maxLength={12}
                keyboardType="number-pad"
                value={player.uid}
                onChangeText={(value) => update(index, 'uid', value)}
                errorText={fieldErrors[`players.${index}.uid`]}
              />
            </View>
          ))}
          <Button label="Continue" onPress={toReview} />
        </>
      )}

      {(step.name === 'review' || step.name === 'submitting') && (
        <>
          <View style={styles.summary}>
            <Row label="Starts" value={formatDateTime(tournament.scheduled_start_at)} />
            <Row label="Mode" value={`${MODE_LABELS[tournament.mode]} · ${tournament.required_player_count} ${tournament.required_player_count === 1 ? 'player' : 'players'}`} />
            <Row label={tournament.required_player_count === 1 ? 'In-game name' : 'Players'} value={players.map((player) => player.inGameName.trim()).join(', ')} />
            <View style={styles.divider} />
            <View style={styles.row}>
              <Text style={styles.rowLabel}>Entry fee</Text>
              {free ? <Text style={[styles.rowValue, { color: semanticColors.success }]}>Free</Text> : <CoinAmount value={tournament.entry_fee} size={16} />}
            </View>
            {!free && (
              <>
                <View style={styles.row}>
                  <Text style={styles.rowLabel}>Your balance</Text>
                  {balance === null ? <Text style={styles.rowValue}>—</Text> : <CoinAmount value={wallet?.total_balance} size={16} />}
                </View>
                {balance !== null && !short && (
                  <View style={styles.row}>
                    <Text style={styles.rowLabel}>After joining</Text>
                    <CoinAmount value={balance - fee} size={16} />
                  </View>
                )}
              </>
            )}
          </View>

          {short && (
            <Notice tone="warning">{`You need ${formatCoins(fee)} coins and have ${formatCoins(balance)}. Add coins to join.`}</Notice>
          )}
          {step.name === 'review' && step.error ? <Notice tone="danger">{step.error}</Notice> : null}

          {short || (step.name === 'review' && step.insufficient) ? (
            <Button
              label="Add coins"
              artwork="deposit"
              variant="success"
              onPress={() => {
                onClose();
                pushOnce('/deposit');
              }}
            />
          ) : (
            <Button
              label={free ? 'Confirm and join' : `Pay ${formatCoins(fee)} coins and join`}
              variant="success"
              onPress={submit}
              loading={submitting}
              accessibilityLabel={free ? 'Confirm and join' : `Pay ${formatCoins(fee)} coins and join`}
            />
          )}
          <Button label="Back" variant="secondary" onPress={() => setStep({ name: 'players' })} disabled={submitting} />
        </>
      )}

      {step.name === 'done' && (
        <>
          <View style={styles.success} accessibilityLiveRegion="polite">
            <Icon name="check-circle" size={52} />
            <Text style={styles.successText}>Your entry is confirmed.</Text>
            {Number(step.entry.entry_fee_paid) > 0 ? (
              <View style={styles.charged}>
                <Text style={styles.rowLabel}>Entry fee paid</Text>
                <CoinAmount value={step.entry.entry_fee_paid} size={16} />
              </View>
            ) : null}
            <Text style={styles.help}>Room ID and password appear on the tournament once staff release them.</Text>
          </View>
          {step.entry.players.map((player) => (
            <Row key={player.slot_number} label={player.slot_number === 1 ? 'Player 1 (you)' : `Player ${player.slot_number}`} value={player.in_game_name} />
          ))}
          {!onDetailsScreen && (
            <Button
              label="View tournament"
              onPress={() => {
                onClose();
                pushOnce(`/tournament/${tournament.id}`);
              }}
            />
          )}
          <Button label="Done" variant="secondary" onPress={onClose} />
        </>
      )}
    </Sheet>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  const styles = useStyles();

  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue} numberOfLines={2}>
        {value}
      </Text>
    </View>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  help: { ...TYPE.body, color: c.fg2 },
  player: { gap: SPACE.xs, padding: SPACE.sm, borderRadius: RADIUS.md, backgroundColor: c.surface2, borderWidth: 1, borderColor: c.border },
  playerLabel: { ...TYPE.bodyStrong, color: c.fg },
  summary: { gap: SPACE.xs, padding: SPACE.sm, borderRadius: RADIUS.md, backgroundColor: c.surface2, borderWidth: 1, borderColor: c.border },
  divider: { height: 1, backgroundColor: c.border, marginVertical: 2 },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: SPACE.sm, minHeight: 24 },
  rowLabel: { ...TYPE.body, color: c.fg3 },
  rowValue: { ...TYPE.bodyStrong, color: c.fg, flexShrink: 1, textAlign: 'right' },
  success: { alignItems: 'center', gap: SPACE.xs, paddingVertical: SPACE.xs },
  successText: { ...TYPE.cardTitle, color: c.fg },
  charged: { flexDirection: 'row', alignItems: 'center', gap: SPACE.xs },
}));
