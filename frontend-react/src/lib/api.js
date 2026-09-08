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
export function apiError(err, fallback = 'Something went wrong.') {
  if (err?.response?.data) {
    const data = err.response.data;
    if (typeof data.message === 'string' && data.message) return data.message;
    if (data.errors && typeof data.errors === 'object') {
      const first = Object.values(data.errors)[0];
      if (Array.isArray(first)) return first[0];
      if (typeof first === 'string') return first;
    }
  }
  if (err?.message) return err.message;
  return fallback;
}

/** Normalise a single error into a key -> [messages] map. */
export function errorMap(err) {
  const errors = err?.response?.data?.errors;
  if (errors && typeof errors === 'object') return errors;
  if (err?.response?.data?.message) {
    return { _server: [err.response.data.message] };
  }
  return { _server: [apiError(err)] };
}