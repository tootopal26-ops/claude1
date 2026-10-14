import { api, ApiError } from '../core/api.js';
import { base } from '../core/dom.js';
import { fail } from '../core/toast.js';

let leaving = false;

/** Admin API call: a lapsed session sends the user back to the sign-in page instead of showing a vague error. */
export async function call(path, opts = {}) {
  try {
    return await api(path, opts);
  } catch (e) {
    if (e instanceof ApiError && e.status === 401 && !leaving) {
      leaving = true;
      fail('Session expired', 'Please sign in again.');
      setTimeout(() => { location.assign(base() + '/admin/login?expired=1'); }, 900);
    }
    if (e instanceof ApiError && e.code === 'password_change_required' && !leaving) {
      leaving = true;
      location.assign(base() + '/admin/security?force=1');
    }
    throw e;
  }
}

export const get = (path, params = {}, opts = {}) => {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== '' && v != null) q.set(k, String(v));
  const s = q.toString();
  return call('/admin/api/' + path + (s ? '?' + s : ''), opts);
};
export const post = (path, body = {}, opts = {}) => call('/admin/api/' + path, { method: 'POST', body, ...opts });
export const put = (path, body = {}, opts = {}) => call('/admin/api/' + path, { method: 'PUT', body, ...opts });
export const patch = (path, body = {}, opts = {}) => call('/admin/api/' + path, { method: 'PATCH', body, ...opts });
export const del = (path, opts = {}) => call('/admin/api/' + path, { method: 'DELETE', ...opts });
/** multipart upload (images) */
export const upload = (path, file, opts = {}) => {
  const fd = new FormData();
  fd.append('file', file);
  return call('/admin/api/' + path, { method: 'POST', body: fd, timeout: 60000, ...opts });
};
