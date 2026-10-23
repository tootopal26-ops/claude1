import { useFocusEffect } from 'expo-router';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState } from 'react-native';

import { fetchSections } from '../api/sections';
import { useAuth } from '../auth/AuthContext';
import type { SectionStatus } from '../types';

type SectionsContextValue = {
  /** The sections the server told us about; null until the first successful load. */
  sections: SectionStatus[] | null;
  loading: boolean;
  failed: boolean;
  refresh: () => Promise<void>;
  /** Replace one section with the status the server returned after an unlock. */
  apply: (section: SectionStatus) => void;
};

const SectionsContext = createContext<SectionsContextValue | undefined>(undefined);

/**
 * The unlockable sections and their prices, always from the server: fetched on
 * sign-in, on return to the app and on focus. A lock is never decided locally —
 * the app only shows what the backend says, and a section flips to unlocked only
 * from a response that confirms the purchase.
 */
export function SectionsProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const userId = user?.id ?? null;
  const [sections, setSections] = useState<SectionStatus[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const inFlight = useRef<Promise<void> | null>(null);

  const refresh = useCallback(async () => {
    if (userId === null) return;
    if (inFlight.current) return inFlight.current;

    const run = (async () => {
      try {
        const response = await fetchSections();
        setSections(response.data);
        setFailed(false);
      } catch {
        setFailed(true);
      } finally {
        setLoading(false);
        inFlight.current = null;
      }
    })();

    inFlight.current = run;
    return run;
  }, [userId]);

  // A different (or no) player must never see the previous player's unlocks.
  useEffect(() => {
    setSections(null);
    setFailed(false);
    inFlight.current = null;

    if (userId !== null) {
      setLoading(true);
      refresh();
    } else {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') refresh();
    });

    return () => subscription.remove();
  }, [refresh]);

  const apply = useCallback((section: SectionStatus) => {
    setSections((current) => (current ? current.map((item) => (item.key === section.key ? section : item)) : [section]));
  }, []);

  const value = useMemo(() => ({ sections, loading, failed, refresh, apply }), [sections, loading, failed, refresh, apply]);

  return <SectionsContext.Provider value={value}>{children}</SectionsContext.Provider>;
}

export function useSections(): SectionsContextValue {
  const context = useContext(SectionsContext);

  if (!context) {
    throw new Error('useSections must be used within a SectionsProvider');
  }

  return context;
}

/** Refresh the sections each time the calling screen gains focus. */
export function useRefreshSectionsOnFocus() {
  const { refresh } = useSections();

  useFocusEffect(
    useCallback(() => {
      refresh();
    }, [refresh]),
  );
}
