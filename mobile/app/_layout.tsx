import { useEffect, type ReactNode } from 'react';
import * as Notifications from 'expo-notifications';
import { router, Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AdsProvider } from '../src/ads/AdsContext';
import { SoundProvider } from '../src/sound/SoundContext';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { LegalAcceptanceGate } from '../src/components/LegalAcceptanceGate';
import { BootScreen } from '../src/components/layout/BootScreen';
import { SectionsProvider } from '../src/sections/SectionsContext';
import { DURATION, useReducedMotion } from '../src/theme/motion';
import { ThemeProvider, useTheme } from '../src/theme/ThemeContext';
import { WalletProvider } from '../src/wallet/WalletContext';

// The tabs sit under every pushed screen, so a cold start straight into a tournament (from a
// notification) still has Home to go back to.
export const unstable_settings = { anchor: '(tabs)' };

function useNotificationTapNavigation() {
  useEffect(() => {
    const subscription = Notifications.addNotificationResponseReceivedListener((response) => {
      const tournamentId = response.notification.request.content.data?.tournament_id;
      if (typeof tournamentId === 'string' && tournamentId.length > 0) {
        router.push(`/tournament/${tournamentId}`);
      } else {
        router.push('/notifications');
      }
    });

    return () => subscription.remove();
  }, []);
}

/**
 * Decides what the app shows before any screen: the branded boot screen while the saved
 * sign-in is checked (or, if the server cannot be reached, the same screen with Retry), the
 * acceptance screen whenever a signed-in player has an outstanding legal document — real the
 * moment staff publish a revision to one they had accepted — and otherwise the navigator.
 */
function AppGate() {
  const { user, isLoading, bootError, retryBoot, needsLegalAcceptance } = useAuth();

  if (bootError) {
    return <BootScreen error={bootError} onRetry={retryBoot} />;
  }

  if (isLoading && !user) {
    return <BootScreen />;
  }

  if (user && needsLegalAcceptance) {
    return <LegalAcceptanceGate />;
  }

  return <ThemedStack signedIn={user !== null} />;
}

/**
 * Paints the theme's canvas behind everything (so no white strip can show
 * behind the status bar, the keyboard or between screens) and keeps the
 * status-bar icons light — every Mini Max theme is dark.
 */
function ThemedRoot({ children }: { children: ReactNode }) {
  const { colors } = useTheme();

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <StatusBar style="light" />
      {children}
    </View>
  );
}

/**
 * Every screen outside the three tabs is pushed on this stack and slides in from the right
 * (a fade under reduced motion); Android Back and the header back button pop it. Signed-out
 * players can reach only sign-in and the legal texts — the guard, not each screen, enforces it.
 */
function ThemedStack({ signedIn }: { signedIn: boolean }) {
  const { colors } = useTheme();
  const reduced = useReducedMotion();

  return (
    <Stack
      screenOptions={{
        headerShown: true,
        headerStyle: { backgroundColor: colors.bg },
        headerTintColor: colors.fg,
        headerTitleStyle: { color: colors.fg, fontWeight: '700', fontSize: 17 },
        headerShadowVisible: false,
        headerBackButtonDisplayMode: 'minimal',
        contentStyle: { backgroundColor: colors.bg },
        animation: reduced ? 'fade' : 'slide_from_right',
        animationDuration: DURATION.screen,
      }}
    >
      <Stack.Protected guard={signedIn} redirectTo="/login">
        <Stack.Screen name="(tabs)" options={{ headerShown: false, title: 'Home' }} />
        <Stack.Screen name="tournaments" options={{ title: 'Tournaments' }} />
        <Stack.Screen name="game/[id]" options={{ title: 'Tournaments' }} />
        <Stack.Screen name="tournament/[id]/index" options={{ title: 'Tournament' }} />
        <Stack.Screen name="tournament/[id]/results" options={{ title: 'Results' }} />
        <Stack.Screen name="account" options={{ title: 'My Profile' }} />
        <Stack.Screen name="notifications" options={{ title: 'Notifications' }} />
        <Stack.Screen name="wallet" options={{ title: 'Wallet' }} />
        <Stack.Screen name="deposit" options={{ title: 'Add coins' }} />
        <Stack.Screen name="withdraw" options={{ title: 'Withdraw' }} />
        <Stack.Screen name="settings" options={{ title: 'Settings' }} />
        <Stack.Screen name="matches" options={{ title: 'My matches' }} />
        <Stack.Screen name="ads-earning" options={{ title: 'Ads Earning' }} />
      </Stack.Protected>
      <Stack.Protected guard={!signedIn} redirectTo="/">
        <Stack.Screen name="login" options={{ headerShown: false, animation: 'fade' }} />
      </Stack.Protected>
      <Stack.Screen name="legal" options={{ title: 'Terms and privacy' }} />
    </Stack>
  );
}

export default function RootLayout() {
  useNotificationTapNavigation();

  return (
    <SafeAreaProvider>
      <ThemeProvider>
        <SoundProvider>
          <ThemedRoot>
            <AuthProvider>
              <WalletProvider>
                <SectionsProvider>
                  <AdsProvider>
                    <AppGate />
                  </AdsProvider>
                </SectionsProvider>
              </WalletProvider>
            </AuthProvider>
          </ThemedRoot>
        </SoundProvider>
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
