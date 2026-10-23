import { useFocusEffect } from 'expo-router';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState } from 'react-native';

import { fetchWallet } from '../api/wallet';
import { useAuth } from '../auth/AuthContext';
import type { Wallet } from '../types';

type WalletContextValue = {
  /** The last balance the server told us; null until the first successful load. */
  wallet: Wallet | null;
  /** True while there is no balance yet and one is being fetched. */
  loading: boolean;
  /** True while a refresh is running (the previous balance stays on screen). */
  refreshing: boolean;
  /** The latest fetch failed. With a balance on screen it is now possibly out of date. */
  failed: boolean;
  /** When the balance on screen was fetched (ms since epoch). */
  updatedAt: number | null;
  /** Fetch the balance now. Recent results are reused unless `force` is set. */
  refresh: (options?: { force?: boolean }) => Promise<void>;
};

const WalletContext = createContext<WalletContextValue | undefined>(undefined);

const FRESH_FOR_MS = 4000;

/**
 * The player's wallet balance, fetched from the server (the source of truth) and
 * shared by every screen. It is never cached across sessions or hard-coded: it is
 * fetched on sign-in, whenever the app returns to the foreground, whenever a
 * screen that shows it gains focus, and right after anything that changes it
 * (a join, a redeem, an unlock). Concurrent requests share one fetch, and a
 * refresh keeps the old number on screen so it never flickers.
 */
export function WalletProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [failed, setFailed] = useState(false);
  const [updatedAt, setUpdatedAt] = useState<number | null>(null);
  const inFlight = useRef<Promise<void> | null>(null);
  const userId = user?.id ?? null;

  const refresh = useCallback(
    async (options?: { force?: boolean }) => {
      if (userId === null) return;
      if (inFlight.current) return inFlight.current;
      if (!options?.force && updatedAt !== null && Date.now() - updatedAt < FRESH_FOR_MS) return;

      const run = (async () => {
        setRefreshing(true);
        try {
          const response = await fetchWallet();
          setWallet(response.data);
          setUpdatedAt(Date.now());
          setFailed(false);
        } catch {
          setFailed(true);
        } finally {
          setRefreshing(false);
          setLoading(false);
          inFlight.current = null;
        }
      })();

      inFlight.current = run;
      return run;
    },
    [userId, updatedAt],
  );

  // A different (or no) player must never see the previous player's balance.
  useEffect(() => {
    setWallet(null);
    setUpdatedAt(null);
    setFailed(false);
    inFlight.current = null;

    if (userId !== null) {
      setLoading(true);
      refresh({ force: true });
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

  const value = useMemo(
    () => ({ wallet, loading: loading && wallet === null, refreshing, failed, updatedAt, refresh }),
    [wallet, loading, refreshing, failed, updatedAt, refresh],
  );

  return <WalletContext.Provider value={value}>{children}</WalletContext.Provider>;
}

export function useWallet(): WalletContextValue {
  const context = useContext(WalletContext);

  if (!context) {
    throw new Error('useWallet must be used within a WalletProvider');
  }

  return context;
}

/** Refresh the shared balance each time the calling screen gains focus. */
export function useRefreshWalletOnFocus() {
  const { refresh } = useWallet();

  useFocusEffect(
    useCallback(() => {
      refresh();
    }, [refresh]),
  );
}
