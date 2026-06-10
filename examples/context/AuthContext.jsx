import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiFetch, setWorkspaceLockedHandler } from '../utils/apiClient';
import { TOKEN_KEY } from '../utils/constants';
import {
  clearAuthStorage,
  getAuthSession,
  isWorkspaceLocked,
  persistWorkspaceFromUser,
} from '../utils/workspaceStorage';
import { hasPermission } from '../utils/permissions';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const navigate = useNavigate();
  const [session, setSession] = useState(() => getAuthSession());
  const [loading, setLoading] = useState(true);

  const applySession = useCallback((payload) => {
    const next = persistWorkspaceFromUser(payload);
    setSession(next);
    return next;
  }, []);

  const refresh = useCallback(async () => {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) {
      setSession(null);
      setLoading(false);
      return null;
    }
    try {
      const data = await apiFetch('/user', { method: 'GET' });
      return applySession(data);
    } catch {
      return getAuthSession();
    } finally {
      setLoading(false);
    }
  }, [applySession]);

  useEffect(() => {
    setWorkspaceLockedHandler(() => navigate('/billing-locked', { replace: true }));
    refresh();
  }, [navigate, refresh]);

  const logout = useCallback(() => {
    const token = localStorage.getItem(TOKEN_KEY);
    if (token) {
      apiFetch('/logout', { method: 'POST' }).catch(() => {});
    }
    clearAuthStorage();
    setSession(null);
    navigate('/', { replace: true });
  }, [navigate]);

  const switchWorkspace = useCallback(
    async (workspaceId) => {
      const data = await apiFetch('/me/workspace', {
        method: 'POST',
        body: JSON.stringify({ workspace_id: Number(workspaceId) }),
      });
      return applySession(data);
    },
    [applySession]
  );

  const loginWithResponse = useCallback(
    (data) => {
      if (data?.token) localStorage.setItem(TOKEN_KEY, data.token);
      return applySession(data);
    },
    [applySession]
  );

  const value = useMemo(
    () => ({
      session,
      user: session?.user ?? null,
      currentWorkspace: session?.current_workspace ?? null,
      workspaces: session?.workspaces ?? [],
      subscription: session?.current_workspace?.subscription ?? null,
      permissions: session?.current_workspace?.membership?.permissions ?? [],
      role: session?.current_workspace?.membership?.role ?? null,
      loading,
      locked: isWorkspaceLocked(session),
      refresh,
      logout,
      switchWorkspace,
      loginWithResponse,
      can: (perm) => hasPermission(session, perm),
    }),
    [session, loading, refresh, logout, switchWorkspace, loginWithResponse]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth debe usarse dentro de AuthProvider');
  return ctx;
}
