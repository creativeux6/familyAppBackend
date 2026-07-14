import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, clearSession, getCachedUser, getToken, isAdminRole, setSession } from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => getCachedUser());
  const [booting, setBooting] = useState(() => Boolean(getToken()));

  const refreshMe = useCallback(async () => {
    const token = getToken();
    if (!token) {
      setUser(null);
      setBooting(false);
      return null;
    }

    try {
      const data = await api('/auth/me');
      setUser(data.user);
      setSession(token, data.user);
      return data.user;
    } catch {
      clearSession();
      setUser(null);
      return null;
    } finally {
      setBooting(false);
    }
  }, []);

  useEffect(() => {
    refreshMe();
  }, [refreshMe]);

  const login = useCallback(async (phone, password) => {
    const data = await api('/auth/login', {
      method: 'POST',
      body: { phone, password },
    });
    setSession(data.access_token, data.user);
    setUser(data.user);
    return data.user;
  }, []);

  const register = useCallback(async (payload) => {
    const data = await api('/auth/register', {
      method: 'POST',
      body: payload,
    });
    setSession(data.access_token, data.user);
    setUser(data.user);
    return data.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api('/auth/logout', { method: 'POST' });
    } catch {
      // ignore
    }
    clearSession();
    setUser(null);
  }, []);

  const value = useMemo(
    () => ({
      user,
      booting,
      isAdmin: isAdminRole(user),
      login,
      register,
      logout,
      refreshMe,
    }),
    [user, booting, login, register, logout, refreshMe],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return ctx;
}
