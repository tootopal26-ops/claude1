import type { EventSubscription } from 'expo';
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

/**
 * FCM can reissue a device's push token at any time (app reinstall, Google Play Services
 * data reset, token rotation) — without this, a stale token would sit in the backend
 * forever and that device would silently stop receiving pushes. Mirrors
 * registerForPushNotificationsAsync's own permission check and error-swallowing so a
 * logged-out or permission-denied device still fails silently.
 */
export function addPushTokenRefreshListener(): EventSubscription {
  return Notifications.addPushTokenListener((event) => {
    if (!Device.isDevice || event.data === lastRegisteredToken) {
      return;
    }

    void (async () => {
      const { status } = await Notifications.getPermissionsAsync();
      if (status !== 'granted') {
        return;
      }

      try {
        await registerPushToken(event.data, Platform.OS === 'ios' ? 'ios' : 'android', Device.modelName ?? undefined);
        lastRegisteredToken = event.data;
      } catch {
        // Network or backend hiccup — the next successful registration (login, app
        // start) will retry with whatever token is current at that time.
      }
    })();
  });
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
