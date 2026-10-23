import { Pressable, Text, View } from 'react-native';

import { iconForSection, routeForSection } from '../sections/registry';
import { glassPanel, withOpacity } from '../theme/glass';
import { Icon } from '../theme/icons';
import { makeStyles } from '../theme/ThemeContext';
import type { SectionStatus } from '../types';
import { formatCoins } from '../utils/coins';
import { openAppLink } from '../utils/links';

type Props = {
  section: SectionStatus;
  /** Open the confirmation dialog for this section. */
  onUnlock: (section: SectionStatus) => void;
};

/**
 * A section that can cost coins. Left: its icon, title and a short description.
 * Right: a clear lock/unlock action — `Unlock · 250 Coins` while it is locked (the
 * price is the backend's), or an Unlocked state once the server has confirmed the
 * purchase, after which the card simply opens the section and offers no second purchase.
 */
export function SectionCard({ section, onUnlock }: Props) {
  const styles = useStyles();
  const route = routeForSection(section.key);
  const icon = iconForSection(section);
  const price = section.price;

  const body = (
    <>
      <View style={styles.tile}>
        <Icon name={icon} size={24} />
      </View>
      <View style={styles.body}>
        <Text style={styles.title} numberOfLines={2}>
          {section.title}
        </Text>
        <Text style={styles.description} numberOfLines={3}>
          {section.description}
        </Text>
      </View>
    </>
  );

  if (!section.unlocked && price !== null) {
    const label = `Unlock · ${formatCoins(price)} Coins`;

    return (
      <View style={styles.card}>
        {body}
        <Pressable
          onPress={() => onUnlock(section)}
          accessibilityRole="button"
          accessibilityLabel={`Unlock ${section.title} for ${formatCoins(price)} coins`}
          accessibilityHint="Asks you to confirm before any coins are spent"
          hitSlop={4}
          style={({ pressed }) => [styles.action, pressed && styles.actionPressed]}
        >
          <Icon name="lock" size={16} />
          <Text style={styles.actionText}>{label}</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <Pressable
      onPress={() => openAppLink(route)}
      disabled={!route}
      accessibilityRole={route ? 'button' : undefined}
      accessibilityLabel={`${section.title}. Unlocked${route ? '. Open' : ''}`}
      style={({ pressed }) => [styles.card, pressed && styles.cardPressed]}
    >
      {body}
      <View style={styles.unlocked}>
        <Icon name="unlock" size={16} />
        <Text style={styles.unlockedText}>Unlocked</Text>
      </View>
    </Pressable>
  );
}

const useStyles = makeStyles(({ colors: c }) => ({
  // The row holds icon, text and action. Sized so all three share one line on a 360dp phone;
  // when there is genuinely no room (a narrower screen or a large system font) the action drops
  // to its own line, still on the right, instead of squeezing the title.
  card: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    columnGap: 8,
    rowGap: 10,
    padding: 12,
    borderRadius: 18,
    ...glassPanel(c, 'surface2'),
    marginBottom: 20,
  },
  cardPressed: { backgroundColor: c.surface3 },
  tile: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: c.brandSoft,
    borderWidth: 1,
    borderColor: c.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  body: { flexGrow: 1, flexShrink: 1, flexBasis: 96, minWidth: 96 },
  title: { color: c.fg, fontSize: 15, fontWeight: '700' },
  description: { color: c.fg2, fontSize: 12, lineHeight: 16, marginTop: 2 },
  // The one action on the card: a pill with the lock, the verb and the backend's price.
  action: {
    minHeight: 44,
    marginLeft: 'auto',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    paddingHorizontal: 9,
    borderRadius: 22,
    backgroundColor: c.brandSoft,
    borderWidth: 1,
    borderColor: withOpacity(c.brand, 0.55),
  },
  actionPressed: { backgroundColor: c.surface3 },
  actionText: { color: c.fg, fontSize: 12, fontWeight: '700' },
  unlocked: { marginLeft: 'auto', flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 44 },
  unlockedText: { color: c.fg2, fontSize: 13, fontWeight: '600' },
}));
