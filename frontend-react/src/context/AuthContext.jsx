import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    // 401 anywhere → drop session.
    const interceptor = api.interceptors.response.use(
      (res) => res,
      (err) => {
        if (err?.response?.status === 401) {
          localStorage.removeItem('token');
          if (active) setUser(null);
        }
        return Promise.reject(err);
      }
    );

    (async () => {
      const token = localStorage.getItem('token');
      if (!token) {
        if (active) setLoading(false);
        return;
      }
      try {
        const { data } = await api.get('/me');
        if (active) setUser(data.user);
      } catch {
        localStorage.removeItem('token');
      } finally {
        if (active) setLoading(false);
      }
    })();

    return () => {
      active = false;
      api.interceptors.response.eject(interceptor);
    };
  }, []);

  const login = useCallback(async (username, password) => {
    const { data } = await api.post('/login', { username, password });
    localStorage.setItem('token', data.token);
    setUser(data.user);
    return data.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/logout');
    } catch {
      /* token already invalid — fine */
    }
    localStorage.removeItem('token');
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}