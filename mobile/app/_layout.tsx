import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthProvider } from '../src/auth/AuthContext';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <Stack screenOptions={{ headerShown: true }}>
          <Stack.Screen name="login" options={{ title: 'Sign in', headerShown: false }} />
          <Stack.Screen name="index" options={{ title: 'Tournaments' }} />
          <Stack.Screen name="tournament/[id]" options={{ title: 'Tournament' }} />
        </Stack>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
