import { base, uuid } from './dom.js';

export class ApiError extends Error {
  constructor(message, { status = 0, code = '', fields = null, retryAfter = 0 } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
    this.retryAfter = retryAfter;
  }
}

let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
export const setCsrf = (t) => { csrfToken = t; };

/**
 * fetch() wrapper:
 *  - JSON in/out, 20 s timeout, same-origin credentials;
 *  - adds the CSRF token (admin) and the X-Requested-With marker the server requires;
 *  - turns every failure into an ApiError with a user-presentable message;
 *  - `idempotency: true|string` sends an Idempotency-Key (double-click / retry safety).
 */
export async function api(path, { method = 'GET', body, headers = {}, timeout = 20000, idempotency = false, signal } = {}) {
  const url = /^https?:/.test(path) ? path : base() + path;
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort('timeout'), timeout);
  if (signal) signal.addEventListener('abort', () => ctrl.abort('aborted'), { once: true });

  const h = { Accept: 'application/json', 'X-Requested-With': 'smb', ...headers };
  let payload;
  if (body instanceof FormData) payload = body;                 // multipart: the browser sets the boundary
  else if (body !== undefined) { h['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
  if (csrfToken && method !== 'GET') h['X-CSRF-Token'] = csrfToken;
  if (idempotency) h['Idempotency-Key'] = typeof idempotency === 'string' ? idempotency : uuid();

  let res;
  try {
    res = await fetch(url, { method, headers: h, body: payload, signal: ctrl.signal, credentials: 'same-origin', cache: 'no-store' });
  } catch (e) {
    if (ctrl.signal.aborted) {
      throw new ApiError(ctrl.signal.reason === 'timeout' ? 'The request took too long. Please check your connection and try again.' : 'Request cancelled.', { code: ctrl.signal.reason === 'timeout' ? 'timeout' : 'aborted' });
    }
    throw new ApiError('Network error. Please check your connection and try again.', { code: 'network' });
  } finally {
    clearTimeout(timer);
  }

  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { /* non-JSON error page */ }

  if (!res.ok || (data && data.ok === false)) {
    const err = data?.error || {};
    const generic = res.status >= 500 ? 'Something went wrong. Please try again.' : 'That did not work. Please try again.';
    throw new ApiError(err.message || generic, {
      status: res.status, code: err.code || '', fields: err.fields || null,
      retryAfter: Number(err.retry_after || res.headers.get('Retry-After') || 0),
    });
  }
  return data ?? {};
}
