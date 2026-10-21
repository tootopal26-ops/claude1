import { useEffect } from 'react';
import * as Notifications from 'expo-notifications';
import { router, Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthProvider } from '../src/auth/AuthContext';

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

export default function RootLayout() {
  useNotificationTapNavigation();

  return (
    <SafeAreaProvider>
      <AuthProvider>
        <Stack screenOptions={{ headerShown: true }}>
          <Stack.Screen name="login" options={{ title: 'Sign in', headerShown: false }} />
          <Stack.Screen name="index" options={{ title: 'Tournaments' }} />
          <Stack.Screen name="tournament/[id]" options={{ title: 'Tournament' }} />
          <Stack.Screen name="notifications" options={{ title: 'Notifications' }} />
          <Stack.Screen name="wallet" options={{ title: 'Wallet' }} />
          <Stack.Screen name="deposit" options={{ title: 'Deposit' }} />
          <Stack.Screen name="withdraw" options={{ title: 'Withdraw' }} />
        </Stack>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
