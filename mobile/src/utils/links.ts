import { router } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';

import { pushOnce } from './navigation';

const TAB_PATHS = ['/leaderboard', '/profile', '/'];

/**
 * Open a link set by Admin on a banner: an in-app path (validated server-side against the
 * app's real routes) or an https address, which opens in the in-app browser. Anything else
 * is ignored rather than guessed at.
 */
export function openAppLink(link: string | null | undefined) {
  if (!link) return;

  if (link.startsWith('/')) {
    if (TAB_PATHS.includes(link)) {
      router.navigate(link as '/');
    } else {
      pushOnce(link as '/');
    }
    return;
  }

  if (/^https:\/\//i.test(link)) {
    void WebBrowser.openBrowserAsync(link);
  }
}
