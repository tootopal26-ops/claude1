import { useFocusEffect } from 'expo-router';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState } from 'react-native';

import { useAuth } from '../auth/AuthContext';
import { fetchTokenBalance } from './api';

type TokenContextValue = {
  /** The last token balance the server told us; null until the first successful load. */
  balance: number | null;
  /** True while there is no balance yet and one is being fetched. */
  loading: boolean;
  /** The latest fetch failed. With a balance on screen it is now possibly out of date. */
  failed: boolean;
  /** When the balance on screen was fetched (ms since epoch). */
  updatedAt: number | null;
  /** Fetch the balance now. Recent results are reused unless `force` is set. */
  refresh: (options?: { force?: boolean }) => Promise<void>;
};

const TokenContext = createContext<TokenContextValue | undefined>(undefined);

const FRESH_FOR_MS = 4000;

/**
 * The player's spin-token balance — the same pattern as WalletContext, for the same reason:
 * one real balance, fetched from the server and shared by every screen, never cached across
 * sessions or hard-coded. A spin credits it only once the server has verified the rewarded ad
 * (RewardedClaimService → SpinService); an exchange debits it the same way. Both call
 * `refresh({ force: true })` after a verified claim rather than guessing the new number.
 */
export function TokenProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const [balance, setBalance] = useState<number | null>(null);
  const [loading, setLoading] = useState(false);
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
        try {
          const response = await fetchTokenBalance();
          setBalance(response.data.balance);
          setUpdatedAt(Date.now());
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
    },
    [userId, updatedAt],
  );

  // A different (or no) player must never see the previous player's balance.
  useEffect(() => {
    setBalance(null);
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

  const value = useMemo(() => ({ balance, loading: loading && balance === null, failed, updatedAt, refresh }), [balance, loading, failed, updatedAt, refresh]);

  return <TokenContext.Provider value={value}>{children}</TokenContext.Provider>;
}

export function useTokens(): TokenContextValue {
  const context = useContext(TokenContext);

  if (!context) {
    throw new Error('useTokens must be used within a TokenProvider');
  }

  return context;
}

/** Refresh the shared token balance each time the calling screen gains focus. */
export function useRefreshTokensOnFocus() {
  const { refresh } = useTokens();

  useFocusEffect(
    useCallback(() => {
      refresh();
    }, [refresh]),
  );
}
