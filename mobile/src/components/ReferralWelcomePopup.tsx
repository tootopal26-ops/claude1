import { Text, View } from 'react-native';

import { useAuth } from '../auth/AuthContext';
import { Button } from './Button';
import { CoinAmount } from './CoinAmount';
import { Sheet } from './sheet/Sheet';
import { makeStyles } from '../theme/ThemeContext';
import { SPACE, TYPE } from '../theme/tokens';

/**
 * Shown once, right after a signup that used a valid referral code — the reward was already
 * credited server-side before this ever renders (see ReferralService::applyReferral, called
 * inside AuthController::register's own transaction); this only announces it. The login
 * screen itself can't show this: it unmounts the instant AuthContext sets `user` (see
 * AppGate in app/_layout.tsx), so Home reads the one-shot `pendingReferralReward` instead.
 */
export function ReferralWelcomePopup() {
  const styles = useStyles();
  const { pendingReferralReward, clearPendingReferralReward } = useAuth();

  return (
    <Sheet visible={pendingReferralReward !== null} onClose={clearPendingReferralReward} title="Welcome to Mini Max!" hideClose variant="center">
      <View style={styles.body}>
        <Text style={styles.title}>Referral Successful!</Text>
        <CoinAmount value={pendingReferralReward ?? '0.00'} size={32} textStyle={styles.amount} style={styles.amountRow} />
        <Text style={styles.subtitle}>You received this for joining with a friend's referral code.</Text>
        <Button label="Continue" onPress={clearPendingReferralReward} style={styles.continueButton} />
      </View>
    </Sheet>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  body: { alignItems: 'center', gap: SPACE.xs, paddingTop: SPACE.sm },
  title: { ...TYPE.hero, fontSize: 22, color: c.fg, textAlign: 'center' },
  amountRow: { flexDirection: 'row', alignItems: 'center', gap: SPACE.sm, marginTop: SPACE.sm },
  amount: { fontSize: 32, lineHeight: 38, fontWeight: '800', color: c.fg },
  subtitle: { ...TYPE.body, color: c.fg3, textAlign: 'center', marginTop: SPACE.xxs },
  continueButton: { width: '100%', marginTop: SPACE.md },
}));
