import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { apiRequest } from '../api/client';
import { fetchLegalAcceptanceStatus } from '../api/legal';
import { registerForPushNotificationsAsync, unregisterPushNotificationsAsync } from '../notifications/pushRegistration';
import type { LegalDocument, User } from '../types';
import { clearToken, getToken, setToken } from './tokenStorage';

type LoginPayload = { mobile: string; password: string };
type RegisterPayload = {
  username: string;
  mobile: string;
  email: string;
  date_of_birth: string;
  state: string;
  password: string;
  password_confirmation: string;
  accept_terms: boolean;
};

type AuthContextValue = {
  isLoading: boolean;
  user: User | null;
  needsLegalAcceptance: boolean;
  outstandingLegalDocuments: LegalDocument[];
  refreshLegalStatus: () => Promise<void>;
  login: (payload: LoginPayload) => Promise<void>;
  register: (payload: RegisterPayload) => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [needsLegalAcceptance, setNeedsLegalAcceptance] = useState(false);
  const [outstandingLegalDocuments, setOutstandingLegalDocuments] = useState<LegalDocument[]>([]);

  async function refreshLegalStatus() {
    try {
      const response = await fetchLegalAcceptanceStatus();
      setNeedsLegalAcceptance(response.data.needs_acceptance);
      setOutstandingLegalDocuments(response.data.outstanding);
    } catch {
      // Non-fatal: leave the last known status in place.
    }
  }

  useEffect(() => {
    (async () => {
      const token = await getToken();
      if (!token) {
        setIsLoading(false);
        return;
      }
      try {
        const payload = await apiRequest<{ data: User }>('/auth/me');
        setUser(payload.data);
        void registerForPushNotificationsAsync();
        void refreshLegalStatus();
      } catch {
        await clearToken();
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      isLoading,
      user,
      needsLegalAcceptance,
      outstandingLegalDocuments,
      refreshLegalStatus,
      async login(payload) {
        const response = await apiRequest<{ data: { token: string; user: User } }>('/auth/login', {
          method: 'POST',
          body: payload,
          authenticated: false,
        });
        await setToken(response.data.token);
        setUser(response.data.user);
        void registerForPushNotificationsAsync();
        void refreshLegalStatus();
      },
      async register(payload) {
        const response = await apiRequest<{ data: { token: string; user: User } }>('/auth/register', {
          method: 'POST',
          body: payload,
          authenticated: false,
        });
        await setToken(response.data.token);
        setUser(response.data.user);
        void registerForPushNotificationsAsync();
        void refreshLegalStatus();
      },
      async logout() {
        await unregisterPushNotificationsAsync();
        try {
          await apiRequest('/auth/logout', { method: 'POST' });
        } catch {
          // best-effort: still clear local state even if the server call fails
        }
        await clearToken();
        setUser(null);
        setNeedsLegalAcceptance(false);
        setOutstandingLegalDocuments([]);
      },
    }),
    [isLoading, user, needsLegalAcceptance, outstandingLegalDocuments]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
