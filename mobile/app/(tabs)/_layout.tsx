import { Tabs } from 'expo-router';

import { BottomNav } from '../../src/components/navigation/BottomNav';
import { DURATION, EASE, useReducedMotion } from '../../src/theme/motion';

// Home is the anchor: the tab the app opens on and the one Android Back returns to.
export const unstable_settings = { anchor: 'index' };

/**
 * The four primary destinations — Leaderboard · Spin · Home · Profile — behind one flat
 * bottom bar. Home is where the app opens and where Android Back returns to from the other
 * three before leaving the app. Switching tabs cross-fades the content with a 4-dp lift
 * (a plain fade under reduced motion); the bar itself never moves.
 */
export default function TabsLayout() {
  const reduced = useReducedMotion();
  const lift = reduced ? 0 : 4;

  return (
    <Tabs
      backBehavior="initialRoute"
      tabBar={(props) => <BottomNav {...props} />}
      screenOptions={{
        headerShown: false,
        sceneStyle: { backgroundColor: 'transparent' },
        transitionSpec: { animation: 'timing', config: { duration: DURATION.card - 20, easing: EASE.out } },
        sceneStyleInterpolator: ({ current }) => ({
          sceneStyle: {
            opacity: current.progress.interpolate({ inputRange: [-1, 0, 1], outputRange: [0, 1, 0] }),
            transform: [{ translateY: current.progress.interpolate({ inputRange: [-1, 0, 1], outputRange: [lift, 0, lift] }) }],
          },
        }),
      }}
    >
      <Tabs.Screen name="leaderboard" options={{ title: 'Leaderboard' }} />
      <Tabs.Screen name="spin" options={{ title: 'Spin' }} />
      <Tabs.Screen name="index" options={{ title: 'Home' }} />
      <Tabs.Screen name="profile" options={{ title: 'Profile' }} />
    </Tabs>
  );
}
