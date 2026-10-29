import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Animated, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useSoundOptional } from '../../sound/SoundContext';
import { glassFloat } from '../../theme/glass';
import { DURATION, EASE, useReducedMotion } from '../../theme/motion';
import { Icon } from '../../theme/icons';
import { makeStyles, useTheme } from '../../theme/ThemeContext';
import { RADIUS, SIZE, SPACE, TYPE } from '../../theme/tokens';

type Props = {
  visible: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  children: ReactNode;
  /**
   * While false (for example during a payment or a join), the backdrop, the close button
   * and Android Back do nothing — an important step is never dismissed by accident.
   */
  dismissible?: boolean;
  /** `bottom` slides up from the screen edge; `center` is a dialog. */
  variant?: 'bottom' | 'center';
  /** Hide the × button (the content provides its own way out). */
  hideClose?: boolean;
};

/**
 * Every popup in the app: a dark backdrop, one rounded dark surface, a title, optional
 * explanation, content, and actions — same widths, radii and motion everywhere. Opens with
 * a backdrop fade and a short upward move; closing plays the reverse before unmounting,
 * so no invisible overlay is ever left behind. Long content scrolls and the keyboard
 * never covers the fields.
 */
export function Sheet({ visible, onClose, title, subtitle, children, dismissible = true, variant = 'center', hideClose }: Props) {
  const styles = useStyles();
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();
  const reduced = useReducedMotion();
  const sound = useSoundOptional();
  const [mounted, setMounted] = useState(visible);
  const progress = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    if (visible) {
      setMounted(true);
      sound.playTransition();
      Animated.timing(progress, { toValue: 1, duration: variant === 'bottom' ? DURATION.sheet : DURATION.modal, easing: EASE.out, useNativeDriver: true }).start();
    } else if (mounted) {
      sound.playClose();
      Animated.timing(progress, { toValue: 0, duration: DURATION.small, easing: EASE.in, useNativeDriver: true }).start(({ finished }) => {
        if (finished) setMounted(false);
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible]);

  if (!mounted) return null;

  const requestClose = () => {
    if (dismissible) onClose();
  };

  const panelMotion = {
    opacity: progress,
    transform: [{ translateY: progress.interpolate({ inputRange: [0, 1], outputRange: [reduced ? 0 : variant === 'bottom' ? 40 : 16, 0] }) }],
  };

  return (
    <Modal visible transparent animationType="none" onRequestClose={requestClose} statusBarTranslucent>
      <KeyboardAvoidingView style={styles.root} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <Animated.View style={[styles.backdrop, { opacity: progress }]}>
          <Pressable style={styles.fill} onPress={requestClose} accessibilityLabel="Close" accessibilityRole="button" disabled={!dismissible} />
        </Animated.View>
        <View
          style={[styles.positioner, variant === 'bottom' ? styles.bottom : styles.center, { paddingTop: insets.top + SPACE.md, paddingBottom: variant === 'bottom' ? 0 : insets.bottom + SPACE.md }]}
          pointerEvents="box-none"
        >
          <Animated.View
            style={[styles.panel, variant === 'bottom' ? styles.panelBottom : styles.panelCenter, variant === 'bottom' && { paddingBottom: insets.bottom + SPACE.md }, panelMotion]}
            accessibilityViewIsModal
          >
            <View style={styles.head}>
              <View style={styles.headText}>
                <Text style={styles.title} accessibilityRole="header">
                  {title}
                </Text>
                {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
              </View>
              {!hideClose && (
                <Pressable onPress={requestClose} disabled={!dismissible} accessibilityRole="button" accessibilityLabel="Close" hitSlop={8} style={[styles.close, !dismissible && styles.closeDisabled]}>
                  <Icon name="close" size={18} color={colors.fg2} />
                </Pressable>
              )}
            </View>
            <ScrollView style={styles.scroll} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" bounces={false} showsVerticalScrollIndicator={false}>
              {children}
            </ScrollView>
          </Animated.View>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const useStyles = makeStyles(({ colors: c, semantic: s }) => ({
  root: { flex: 1 },
  fill: { flex: 1 },
  backdrop: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: s.overlay },
  positioner: { flex: 1, paddingHorizontal: SPACE.md },
  center: { justifyContent: 'center' },
  bottom: { justifyContent: 'flex-end', paddingHorizontal: 0 },
  panel: { ...glassFloat(c), width: '100%', maxWidth: 480, alignSelf: 'center', maxHeight: '100%' },
  panelCenter: { borderRadius: RADIUS.xl },
  panelBottom: { borderTopLeftRadius: RADIUS.xl, borderTopRightRadius: RADIUS.xl, borderBottomWidth: 0 },
  head: { flexDirection: 'row', alignItems: 'flex-start', gap: SPACE.sm, paddingHorizontal: SPACE.lg, paddingTop: SPACE.lg },
  headText: { flex: 1, minWidth: 0, gap: SPACE.xxs },
  title: { ...TYPE.section, fontSize: 18, color: c.fg },
  subtitle: { ...TYPE.body, color: c.fg2 },
  close: {
    width: SIZE.touch - 8,
    height: SIZE.touch - 8,
    borderRadius: RADIUS.pill,
    backgroundColor: c.surface2,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: -SPACE.xxs,
  },
  closeDisabled: { opacity: 0.4 },
  scroll: { flexGrow: 0 },
  content: { padding: SPACE.lg, paddingTop: SPACE.md, gap: SPACE.sm },
}));
