import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';

import { redeemCode } from '../api/redeem';
import { ApiError } from '../api/client';
import { glassFloat } from '../theme/glass';
import { makeStyles, useTheme } from '../theme/ThemeContext';
import type { Wallet } from '../types';
import { Button } from './Button';
import { CoinAmount } from './CoinAmount';
import { Notice } from './Notice';

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
  const { colors, semanticColors } = useTheme();
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
    <Modal visible={visible} transparent animationType="fade" onRequestClose={close} statusBarTranslucent>
      <KeyboardAvoidingView style={styles.backdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <Pressable style={styles.scrim} onPress={close} accessibilityLabel="Close" accessibilityRole="button" />
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
          bounces={false}
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.card} accessibilityViewIsModal>
            {state.phase === 'done' ? (
              <>
                <Text style={styles.title} accessibilityRole="header">
                  Code redeemed
                </Text>
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
                <Button label="Done" onPress={close} style={styles.fullButton} />
              </>
            ) : (
              <>
                <Text style={styles.title} accessibilityRole="header">
                  Redeem Your Code
                </Text>
                <Text style={styles.subtitle}>Enter your 5-character reward code.</Text>

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

                {state.phase === 'entering' && state.error ? (
                  <View style={styles.errorWrap}>
                    <Notice tone="danger">{state.error}</Notice>
                  </View>
                ) : null}

                <Pressable
                  onPress={submit}
                  disabled={!valid || submitting}
                  accessibilityRole="button"
                  accessibilityLabel={submitting ? 'Redeeming' : 'Redeem'}
                  accessibilityState={{ disabled: !valid || submitting, busy: submitting }}
                  style={({ pressed }) => [
                    styles.redeem,
                    (!valid || submitting) && styles.redeemDisabled,
                    pressed && valid && !submitting && styles.redeemPressed,
                  ]}
                >
                  {submitting ? (
                    <ActivityIndicator color={semanticColors.onDanger} />
                  ) : (
                    <Text style={styles.redeemText}>Redeem</Text>
                  )}
                </Pressable>

                <Button label="Cancel" variant="secondary" onPress={close} disabled={submitting} style={styles.fullButton} />
              </>
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  backdrop: { flex: 1, justifyContent: 'center' },
  scrim: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: s.overlay },
  scrollContent: { flexGrow: 1, justifyContent: 'center', padding: 20 },
  // A glass sheet; Android has no backdrop blur here, so it uses the solid fallback (theme/glass.ts).
  card: {
    ...glassFloat(c),
    borderRadius: 22,
    padding: 22,
    gap: 14,
  },
  title: { color: c.fg, fontSize: 20, fontWeight: '700' },
  subtitle: { color: c.fg2, fontSize: 14, lineHeight: 20 },
  input: {
    height: 62,
    borderRadius: 14,
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
  errorWrap: { marginTop: -2 },
  // A deliberate red action: solid red, white label (6.5:1), never a red background.
  redeem: {
    minHeight: 52,
    borderRadius: 14,
    backgroundColor: s.dangerSolid,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.14)',
  },
  redeemDisabled: { opacity: 0.45 },
  redeemPressed: { opacity: 0.88 },
  redeemText: { color: s.onDanger, fontSize: 16, fontWeight: '700', letterSpacing: 0.3 },
  fullButton: { alignSelf: 'stretch' },
  reward: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 8 },
  rewardPlus: { color: s.success, fontSize: 30, fontWeight: '800' },
  rewardValue: { color: c.fg, fontSize: 34, fontWeight: '800' },
  rewardUnit: { color: c.fg3, fontSize: 15, fontWeight: '600' },
  balanceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: c.surface2,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: c.border,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  balanceLabel: { color: c.fg2, fontSize: 13, fontWeight: '600' },
}));
