import { Text } from 'react-native';

import { makeStyles } from '../../theme/ThemeContext';
import { TYPE } from '../../theme/tokens';
import type { Tournament } from '../../types';
import { Button } from '../Button';
import { Sheet } from '../sheet/Sheet';
import { useRetained } from '../../utils/useRetained';

/** The tournament's rules exactly as staff wrote them, in a bottom sheet. */
export function RulesSheet({ tournament, onClose }: { tournament: Tournament | null; onClose: () => void }) {
  const styles = useStyles();
  const shown = useRetained(tournament);

  return (
    <Sheet visible={tournament !== null} onClose={onClose} title="Rules" subtitle={shown?.title} variant="bottom">
      <Text style={styles.rules} selectable>
        {shown?.rules?.trim()}
      </Text>
      <Button label="Got it" variant="secondary" onPress={onClose} />
    </Sheet>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  rules: { ...TYPE.body, lineHeight: 22, color: c.fg2 },
}));
