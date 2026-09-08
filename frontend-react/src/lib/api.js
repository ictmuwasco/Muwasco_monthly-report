import axios from 'axios';

const useProxy = (import.meta.env.VITE_USE_PROXY ?? 'true') === 'true';
const baseURL = useProxy
  ? '/api/v1'
  : import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1';

/**
 * Pre-configured axios client.
 * - baseURL: points at the Laravel v1 API (proxy in dev, or direct URL).
 * - Bearer token attached from localStorage when present.
 * - 401 responses trigger a global logout (see AuthContext listener).
 */
export const api = axios.create({ baseURL });

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

/** Extract a human-friendly message from an axios error response (422/500/etc). */
export function apiError(err, fallback = 'Something went wrong. Please try again.') {
  const res = err?.response;

  if (res?.data) {
    const data = res.data;
    if (typeof data.message === 'string' && data.message) return data.message;
    if (data.errors && typeof data.errors === 'object') {
      const first = Object.values(data.errors)[0];
      if (Array.isArray(first)) return first[0];
      if (typeof first === 'string') return first;
    }
  }

  // Network-level failures — never expose raw axios messages like "Network Error".
  switch (err?.code) {
    case 'ERR_NETWORK':
    case 'ECONNABORTED':
      return 'Cannot reach the server. Check that the backend is running and your connection, then try again.';
    case 'ECONNREFUSED':
      return 'The server is not responding. Please try again in a moment.';
    default:
      break;
  }

  if (res) {
    if (res.status === 429) return 'Too many attempts. Please wait a minute and try again.';
    if (res.status === 500 || res.status === 502 || res.status === 503) {
      return 'A server error occurred. Please try again, and contact support if it persists.';
    }
    if (res.status === 404) return 'The requested item was not found. It may have been deleted.';
    if (res.status === 419) return 'Your session expired. Please refresh the page and sign in again.';
  }

  return fallback;
}

/** Normalise a single error into a key -> [messages] map. */
export function errorMap(err) {
  const errors = err?.response?.data?.errors;
  if (errors && typeof errors === 'object') {
    const entries = Object.entries(errors).map(([k, v]) => [
      k,
      Array.isArray(v) ? v : [v],
    ]);

    // A validation block whose messages are all identical (e.g. the single
    // "The provided credentials are incorrect." sent under `username`) reads
    // better as one plain sentence than as a field-prefixed list.
    const msgs = entries.flatMap(([, v]) => v);
    const allSame = msgs.length > 0 && msgs.every((m) => m === msgs[0]);

    if (allSame) return { _server: msgs.slice(0, 1) };

    // Merge duplicate keys (Laravel can send both `password` and the
    // confirmation dot-syntax key for the same input).
    return Object.fromEntries(entries);
  }
  if (err?.response?.data?.message) {
    return { _server: [err.response.data.message] };
  }
  return { _server: [apiError(err)] };
}