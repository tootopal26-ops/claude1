import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';

import { fetchNotifications } from '../api/notifications';

/**
 * The server's unread notification count, fetched each time the screen using it comes
 * into focus. A failed fetch keeps the last known count rather than inventing one.
 */
export function useUnreadCount(): number {
  const [unread, setUnread] = useState(0);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      fetchNotifications()
        .then((response) => {
          if (active) setUnread(response.meta.unread_count);
        })
        .catch(() => {});

      return () => {
        active = false;
      };
    }, []),
  );

  return unread;
}
