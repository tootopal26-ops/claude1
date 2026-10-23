import { useState } from 'react';
import { Text, TextInput, View } from 'react-native';

import { redeemCode } from '../api/redeem';
import { ApiError } from '../api/client';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../theme/tokens';
import type { Wallet } from '../types';
import { Button } from './Button';
import { CoinAmount } from './CoinAmount';
import { Notice } from './Notice';
import { Sheet } from './sheet/Sheet';

const CODE_LENGTH = 5;

type Props = {
  visible: boolean;
  onClose: () => void;
  /** Called only after the server has confirmed a redemption, with the balance it returned. */
  onRedeemed?: (balance: Wallet) => void;
};

type State =
  | { phase: 'entering'; error: string | null }
  | { phase: 'submitting' }
  | { phase: 'done'; coins: number; balance: Wallet };

const INITIAL: State = { phase: 'entering', error: null };

/**
 * Redeem a reward code without leaving the app. The success screen is shown only
 * after the server confirms the redemption, using the amount and balance it
 * returns. If the request fails in a way that leaves the outcome unknown (no
 * connection, a timeout) the dialog says exactly that instead of guessing, and
 * retrying is safe: the server never credits one player twice.
 */
export function RedeemCodeDialog({ visible, onClose, onRedeemed }: Props) {
  const { colors } = useTheme();
  const styles = useStyles();
  const [code, setCode] = useState('');
  const [state, setState] = useState<State>(INITIAL);

  const submitting = state.phase === 'submitting';
  const valid = code.length === CODE_LENGTH;

  function close() {
    if (submitting) return;
    setCode('');
    setState(INITIAL);
    onClose();
  }

  // Uppercase, drop spaces and punctuation (so a pasted "k7p-4x " works), cap at five.
  function onChange(text: string) {
    setCode(text.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, CODE_LENGTH));
    if (state.phase === 'entering' && state.error) setState(INITIAL);
  }

  async function submit() {
    if (!valid || submitting) return;
    setState({ phase: 'submitting' });

    try {
      const response = await redeemCode(code);
      setState({ phase: 'done', coins: response.data.coins, balance: response.data.balance });
      onRedeemed?.(response.data.balance);
    } catch (error) {
      if (error instanceof ApiError) {
        const message =
          error.code === 'already_redeemed'
            ? "You've already redeemed this code. Your coins are in your wallet."
            : error.message;
        setState({ phase: 'entering', error: message });
      } else {
        setState({
          phase: 'entering',
          error:
            "We couldn't confirm whether the code was redeemed. Check your wallet balance before trying again — if it was redeemed you'll see the coins.",
        });
      }
    }
  }

  return (
    <Sheet
      visible={visible}
      onClose={close}
      title={state.phase === 'done' ? 'Code redeemed' : 'Redeem Your Code'}
      subtitle={state.phase === 'done' ? undefined : 'Enter your 5-character reward code.'}
      dismissible={!submitting}
    >
      {state.phase === 'done' ? (
        <>
          <View style={styles.reward} accessibilityLiveRegion="polite">
            <Text style={styles.rewardPlus}>+</Text>
            <CoinAmount value={state.coins} size={30} textStyle={styles.rewardValue} />
            <Text style={styles.rewardUnit}>coins</Text>
          </View>
          <Text style={styles.subtitle}>Added to your deposit balance.</Text>
          <View style={styles.balanceRow}>
            <Text style={styles.balanceLabel}>Deposit balance</Text>
            <CoinAmount value={state.balance.deposit_balance} size={16} />
          </View>
          <Button label="Done" onPress={close} />
        </>
      ) : (
        <>
          <TextInput
            value={code}
            onChangeText={onChange}
            onSubmitEditing={submit}
            editable={!submitting}
            autoFocus
            autoCapitalize="characters"
            autoCorrect={false}
            autoComplete="off"
            importantForAutofill="no"
            spellCheck={false}
            maxLength={CODE_LENGTH + 4}
            returnKeyType="done"
            placeholder="•••••"
            placeholderTextColor={colors.fg3}
            selectionColor={colors.brand}
            accessibilityLabel="Reward code, 5 characters"
            accessibilityHint="Letters and numbers only"
            style={[styles.input, state.phase === 'entering' && state.error ? styles.inputError : null]}
          />
          <Text style={styles.counter}>
            {code.length}/{CODE_LENGTH}
          </Text>

          {state.phase === 'entering' && state.error ? <Notice tone="danger">{state.error}</Notice> : null}

          <Button
            label="Redeem"
            variant="danger"
            onPress={submit}
            loading={submitting}
            disabled={!valid}
            accessibilityLabel={submitting ? 'Redeeming' : 'Redeem'}
          />
          <Button label="Cancel" variant="secondary" onPress={close} disabled={submitting} />
        </>
      )}
    </Sheet>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  subtitle: { ...TYPE.body, color: c.fg2, textAlign: 'center' },
  input: {
    height: 62,
    borderRadius: RADIUS.md,
    borderWidth: 1.5,
    borderColor: c.borderStrong,
    backgroundColor: c.surface2,
    color: c.fg,
    fontSize: 28,
    fontWeight: '700',
    letterSpacing: 8,
    textAlign: 'center',
  },
  inputError: { borderColor: s.danger },
  counter: { ...TYPE.meta, color: c.fg3, textAlign: 'right', marginTop: -SPACE.xxs },
  reward: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: SPACE.xs, paddingVertical: SPACE.xs },
  rewardPlus: { color: s.success, fontSize: 30, fontWeight: '800' },
  rewardValue: { color: c.fg, fontSize: 34, fontWeight: '800' },
  rewardUnit: { color: c.fg3, fontSize: 15, fontWeight: '600' },
  balanceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: c.surface2,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: c.border,
    paddingHorizontal: SPACE.sm,
    paddingVertical: SPACE.sm,
  },
  balanceLabel: { ...TYPE.bodyStrong, fontSize: 13, color: c.fg2 },
}));
