import { router } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { Modal, Pressable, ScrollView, Text, View } from 'react-native';

import { ApiError } from '../api/client';
import { unlockSection } from '../api/sections';
import { routeForSection } from '../sections/registry';
import { useSections } from '../sections/SectionsContext';
import { glassFloat } from '../theme/glass';
import { makeStyles } from '../theme/ThemeContext';
import type { SectionStatus, Wallet } from '../types';
import { formatCoins } from '../utils/coins';
import { useWallet } from '../wallet/WalletContext';
import { Button } from './Button';
import { CoinAmount } from './CoinAmount';
import { Notice } from './Notice';

type Props = {
  section: SectionStatus | null;
  visible: boolean;
  onClose: () => void;
};

type State =
  | { phase: 'confirm'; notice: { tone: 'warning' | 'danger'; text: string } | null }
  | { phase: 'submitting' }
  | { phase: 'insufficient'; required: string; available: string }
  | { phase: 'not_for_sale' }
  | { phase: 'done'; charged: string; already: boolean; balance: Wallet };

const CONFIRM: State = { phase: 'confirm', notice: null };

/**
 * Confirms and performs an unlock. Nothing is spent until the player presses the
 * confirm button; the request carries the price they confirmed, and the section only
 * shows as unlocked after the server's response says so. A second tap while the
 * request is running is ignored, a failure leaves the player exactly where they were,
 * and an unknown outcome (no connection) says so and is safe to retry — the server
 * never charges one player twice for one section.
 */
export function UnlockSectionDialog({ section, visible, onClose }: Props) {
  const styles = useStyles();
  const { apply, refresh: refreshSections } = useSections();
  const { wallet, refresh: refreshWallet } = useWallet();
  const [state, setState] = useState<State>(CONFIRM);
  const [price, setPrice] = useState<number | null>(section?.price ?? null);
  const busy = useRef(false);

  // Each time the dialog opens: start from the confirmation with the backend's current price
  // and a fresh balance to show beside it.
  useEffect(() => {
    if (!visible) return;
    setState(CONFIRM);
    setPrice(section?.price ?? null);
    refreshWallet();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible, section?.key]);

  const submitting = state.phase === 'submitting';

  function close() {
    if (submitting) return;
    onClose();
  }

  async function submit() {
    if (!section || price === null || busy.current) return;
    busy.current = true;
    setState({ phase: 'submitting' });

    try {
      const response = await unlockSection(section.key, price);
      apply(response.data.section);
      void refreshWallet({ force: true });
      setState({ phase: 'done', charged: response.data.charged, already: response.data.already_unlocked, balance: response.data.balance });
    } catch (error) {
      if (error instanceof ApiError) {
        handleRefusal(error);
      } else {
        void refreshSections();
        void refreshWallet({ force: true });
        setState({
          phase: 'confirm',
          notice: {
            tone: 'warning',
            text: "We couldn't confirm whether the unlock went through. You are only ever charged once — check your balance, then try again.",
          },
        });
      }
    } finally {
      busy.current = false;
    }
  }

  function handleRefusal(error: ApiError) {
    switch (error.code) {
      case 'insufficient_balance':
        void refreshWallet({ force: true });
        setState({
          phase: 'insufficient',
          required: String(error.details?.required ?? price ?? ''),
          available: String(error.details?.available ?? wallet?.total_balance ?? ''),
        });
        return;
      case 'price_changed': {
        const next = Number(error.details?.price);
        void refreshSections();
        if (Number.isFinite(next) && next > 0) setPrice(next);
        setState({
          phase: 'confirm',
          notice: { tone: 'warning', text: `The price changed${Number.isFinite(next) ? ` to ${formatCoins(next)} coins` : ''}. Review it and confirm again.` },
        });
        return;
      }
      case 'not_for_sale':
        void refreshSections();
        setState({ phase: 'not_for_sale' });
        return;
      default:
        setState({ phase: 'confirm', notice: { tone: 'danger', text: error.message } });
    }
  }

  function go(path: string) {
    onClose();
    router.push(path as never);
  }

  if (!section) return null;

  const route = routeForSection(section.key);
  const label = price === null ? 'Unlock' : `Unlock · ${formatCoins(price)} Coins`;
  const shortBy =
    state.phase === 'insufficient' ? Math.max(0, Number(state.required) - Number(state.available)) : 0;

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={close} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable style={styles.scrim} onPress={close} accessibilityLabel="Close" accessibilityRole="button" />
        <ScrollView contentContainerStyle={styles.scrollContent} bounces={false} showsVerticalScrollIndicator={false}>
          <View style={styles.card} accessibilityViewIsModal>
            {state.phase === 'done' ? (
              <>
                <Text style={styles.title} accessibilityRole="header">
                  {section.title} unlocked
                </Text>
                <Text style={styles.text} accessibilityLiveRegion="polite">
                  {state.already
                    ? "You'd already unlocked this section — nothing was charged."
                    : `${formatCoins(state.charged)} coins were deducted from your wallet.`}
                </Text>
                <View style={styles.row}>
                  <Text style={styles.rowLabel}>Wallet balance</Text>
                  <CoinAmount value={state.balance.total_balance} size={16} />
                </View>
                {route ? <Button label={`Open ${section.title}`} onPress={() => go(route)} /> : null}
                <Button label="Done" variant={route ? 'secondary' : 'primary'} onPress={close} />
              </>
            ) : state.phase === 'insufficient' ? (
              <>
                <Text style={styles.title} accessibilityRole="header">
                  Not enough coins
                </Text>
                <Text style={styles.text}>Unlocking {section.title} needs more coins than your wallet has right now. Nothing was charged.</Text>
                <View style={styles.rowGroup}>
                  <View style={styles.row}>
                    <Text style={styles.rowLabel}>Price</Text>
                    <CoinAmount value={state.required} size={16} />
                  </View>
                  <View style={styles.row}>
                    <Text style={styles.rowLabel}>You have</Text>
                    <CoinAmount value={state.available} size={16} />
                  </View>
                  <View style={styles.row}>
                    <Text style={styles.rowLabel}>You need</Text>
                    <CoinAmount value={shortBy} size={16} />
                  </View>
                </View>
                <Button label="Add coins" onPress={() => go('/deposit')} />
                <Button label="Redeem a code" variant="secondary" onPress={() => go('/profile')} />
                <Button label="Close" variant="secondary" onPress={close} />
              </>
            ) : state.phase === 'not_for_sale' ? (
              <>
                <Text style={styles.title} accessibilityRole="header">
                  Nothing to unlock
                </Text>
                <Text style={styles.text}>{section.title} isn't locked right now. Nothing was charged.</Text>
                <Button label="Close" onPress={close} />
              </>
            ) : (
              <>
                <Text style={styles.title} accessibilityRole="header">
                  Unlock {section.title}?
                </Text>
                <Text style={styles.text}>
                  {price === null ? '' : `${formatCoins(price)} coins`} will be deducted from your wallet balance — deposit balance first, then
                  withdrawable winnings. You pay once and keep access.
                </Text>
                <View style={styles.rowGroup}>
                  <View style={styles.row}>
                    <Text style={styles.rowLabel}>Price</Text>
                    <CoinAmount value={price} size={16} />
                  </View>
                  <View style={styles.row}>
                    <Text style={styles.rowLabel}>Your balance</Text>
                    <CoinAmount value={wallet?.total_balance} size={16} />
                  </View>
                </View>
                {state.phase === 'confirm' && state.notice ? <Notice tone={state.notice.tone}>{state.notice.text}</Notice> : null}
                <Button label={label} loading={submitting} onPress={submit} accessibilityLabel={submitting ? 'Unlocking' : `${label}. Confirm and pay`} />
                <Button label="Cancel" variant="secondary" onPress={close} disabled={submitting} />
              </>
            )}
          </View>
        </ScrollView>
      </View>
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
  text: { color: c.fg2, fontSize: 14, lineHeight: 20 },
  rowGroup: { gap: 8 },
  row: {
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
  rowLabel: { color: c.fg2, fontSize: 13, fontWeight: '600' },
}));
