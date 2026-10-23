import Constants from 'expo-constants';
import { getToken } from '../auth/tokenStorage';

const API_BASE_URL =
  (Constants.expoConfig?.extra?.apiBaseUrl as string | undefined) ?? 'http://localhost:8000/api/v1';

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;
  /** The API's machine-readable error slug (for example "expired"), when it sends one. */
  code?: string;
  /** The rest of the error body (for example the `required` and `available` of an insufficient balance). */
  details?: Record<string, unknown>;

  constructor(message: string, status: number, errors?: Record<string, string[]>, code?: string, details?: Record<string, unknown>) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.code = code;
    this.details = details;
  }
}

let onUnauthorized: (() => void) | null = null;

/**
 * Called when the server rejects the saved token (401) on a signed-in request — for
 * example after the player signed this device out from another one. AuthProvider uses it
 * to end the local session so the app returns to sign-in instead of failing on every screen.
 */
export function setUnauthorizedHandler(handler: (() => void) | null) {
  onUnauthorized = handler;
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  authenticated?: boolean;
};

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, authenticated = true } = options;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  if (authenticated) {
    const token = await getToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const isJson = response.headers.get('content-type')?.includes('application/json');
  const payload = isJson ? await response.json() : undefined;

  if (!response.ok) {
    if (response.status === 401 && authenticated && path !== '/auth/me') {
      onUnauthorized?.();
    }
    throw new ApiError(
      payload?.message ?? `Request failed with status ${response.status}`,
      response.status,
      payload?.errors,
      typeof payload?.error === 'string' ? payload.error : undefined,
      payload && typeof payload === 'object' ? (payload as Record<string, unknown>) : undefined
    );
  }

  return payload as T;
}
