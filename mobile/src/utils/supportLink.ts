import { Linking } from 'react-native';

import { fetchAppConfig } from '../api/appConfig';

export type SupportLinkOutcome = 'opened' | 'not_configured' | 'unsafe' | 'cannot_open' | 'unavailable';

/** Only https:// pages and mailto: addresses are opened — the same rule the server enforces. */
export function isPermittedSupportUrl(url: string): boolean {
  return /^https:\/\/[^\s/]+\.[^\s]+$/i.test(url) || /^mailto:[^\s@]+@[^\s@]+\.[^\s@]+(\?\S*)?$/i.test(url);
}

/**
 * Opens the Admin-configured support link. The link is read from the server each time
 * (never hard-coded), checked again on the device, and opened with the platform's link
 * handler; every failure comes back as an outcome for the screen to explain, never a crash.
 */
export async function openSupportLink(): Promise<SupportLinkOutcome> {
  let url: string | null;
  try {
    url = (await fetchAppConfig()).data.support_url;
  } catch {
    return 'unavailable';
  }

  if (!url) return 'not_configured';
  if (!isPermittedSupportUrl(url)) return 'unsafe';

  try {
    await Linking.openURL(url);
    return 'opened';
  } catch {
    return 'cannot_open';
  }
}

export const SUPPORT_LINK_MESSAGES: Record<Exclude<SupportLinkOutcome, 'opened'>, string> = {
  not_configured: 'Customer support is not available yet. Please check back soon.',
  unsafe: 'The support link is not set up correctly. Please try again later.',
  cannot_open: 'Your phone could not open the support link. Please try again later.',
  unavailable: 'Could not reach Mini Max. Check your connection and try again.',
};
