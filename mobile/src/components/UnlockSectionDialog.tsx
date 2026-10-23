import { useEffect, useRef, useState } from 'react';
import { Text, View } from 'react-native';

import { ApiError } from '../api/client';
import { unlockSection } from '../api/sections';
import { routeForSection } from '../sections/registry';
import { useSections } from '../sections/SectionsContext';
import { makeStyles } from '../theme/ThemeContext';
import { RADIUS, SPACE, TYPE } from '../theme/tokens';
import type { SectionStatus, Wallet } from '../types';
import { formatCoins } from '../utils/coins';
import { openAppLink } from '../utils/links';
import { useWallet } from '../wallet/WalletContext';
import { Button } from './Button';
import { CoinAmount } from './CoinAmount';
import { Notice } from './Notice';
import { Sheet } from './sheet/Sheet';

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
    openAppLink(path);
  }

  if (!section) return null;

  const route = routeForSection(section.key);
  const label = price === null ? 'Unlock' : `Unlock · ${formatCoins(price)} Coins`;
  const shortBy =
    state.phase === 'insufficient' ? Math.max(0, Number(state.required) - Number(state.available)) : 0;

  const title =
    state.phase === 'done'
      ? `${section.title} unlocked`
      : state.phase === 'insufficient'
        ? 'Not enough coins'
        : state.phase === 'not_for_sale'
          ? 'Nothing to unlock'
          : `Unlock ${section.title}?`;

  return (
    <Sheet visible={visible} onClose={close} title={title} dismissible={!submitting}>
      {state.phase === 'done' ? (
        <>
          <Text style={styles.text} accessibilityLiveRegion="polite">
            {state.already ? "You'd already unlocked this section — nothing was charged." : `${formatCoins(state.charged)} coins were deducted from your wallet.`}
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
          <Button label="Add coins" icon="deposit" onPress={() => go('/deposit')} />
          <Button label="Redeem a code" variant="secondary" onPress={() => go('/profile')} />
          <Button label="Close" variant="secondary" onPress={close} />
        </>
      ) : state.phase === 'not_for_sale' ? (
        <>
          <Text style={styles.text}>{section.title} isn&apos;t locked right now. Nothing was charged.</Text>
          <Button label="Close" onPress={close} />
        </>
      ) : (
        <>
          <Text style={styles.text}>
            {price === null ? '' : `${formatCoins(price)} coins`} will be deducted from your wallet balance — deposit balance first, then withdrawable winnings. You pay
            once and keep access.
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
    </Sheet>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  text: { ...TYPE.body, color: c.fg2 },
  rowGroup: { gap: SPACE.xs },
  row: {
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
  rowLabel: { ...TYPE.bodyStrong, fontSize: 13, color: c.fg2 },
}));
