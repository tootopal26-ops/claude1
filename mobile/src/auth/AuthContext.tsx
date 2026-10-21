import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { apiRequest } from '../api/client';
import type { User } from '../types';
import { clearToken, getToken, setToken } from './tokenStorage';

type LoginPayload = { mobile: string; password: string };
type RegisterPayload = {
  username: string;
  mobile: string;
  email: string;
  password: string;
  password_confirmation: string;
  date_of_birth: string;
};

type AuthContextValue = {
  isLoading: boolean;
  user: User | null;
  login: (payload: LoginPayload) => Promise<void>;
  register: (payload: RegisterPayload) => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

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
      async login(payload) {
        const response = await apiRequest<{ data: { token: string; user: User } }>('/auth/login', {
          method: 'POST',
          body: payload,
          authenticated: false,
        });
        await setToken(response.data.token);
        setUser(response.data.user);
      },
      async register(payload) {
        const response = await apiRequest<{ data: { token: string; user: User } }>('/auth/register', {
          method: 'POST',
          body: payload,
          authenticated: false,
        });
        await setToken(response.data.token);
        setUser(response.data.user);
      },
      async logout() {
        try {
          await apiRequest('/auth/logout', { method: 'POST' });
        } catch {
          // best-effort: still clear local state even if the server call fails
        }
        await clearToken();
        setUser(null);
      },
    }),
    [isLoading, user]
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
