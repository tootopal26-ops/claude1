import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import { deregisterPushToken, registerPushToken } from '../api/notifications';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: false,
    shouldSetBadge: false,
  }),
});

let lastRegisteredToken: string | null = null;

/**
 * Requests push permission and registers this device's raw FCM token with
 * the backend. Returns silently (no throw) if the user denies permission
 * or this isn't a physical device (push tokens don't work on simulators) —
 * the in-app notification list works regardless, per the no-push-permission
 * fallback requirement.
 */
export async function registerForPushNotificationsAsync(): Promise<void> {
  if (!Device.isDevice) {
    return;
  }

  const existing = await Notifications.getPermissionsAsync();
  let status = existing.status;

  if (status !== 'granted') {
    const requested = await Notifications.requestPermissionsAsync();
    status = requested.status;
  }

  if (status !== 'granted') {
    return;
  }

  try {
    const token = await Notifications.getDevicePushTokenAsync();
    lastRegisteredToken = token.data;
    await registerPushToken(token.data, Platform.OS === 'ios' ? 'ios' : 'android', Device.modelName ?? undefined);
  } catch {
    // Network or backend hiccup registering the token — the app still
    // works via in-app notifications, so this is non-fatal.
  }
}

export async function unregisterPushNotificationsAsync(): Promise<void> {
  if (!lastRegisteredToken) {
    return;
  }

  try {
    await deregisterPushToken(lastRegisteredToken);
  } catch {
    // Best-effort: proceed with logout locally even if this call fails.
  } finally {
    lastRegisteredToken = null;
  }
}
