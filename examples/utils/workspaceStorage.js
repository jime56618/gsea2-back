import { AUTH_SESSION_KEY, USER_KEY } from './constants';

/**
 * Normaliza respuesta de login, register o GET /user al formato de sesión.
 * @param {object} payload
 */
export function normalizeAuthSession(payload) {
  if (!payload) return null;

  // Respuesta antigua: user plano con relaciones embebidas
  if (payload.id && payload.email && !payload.user) {
    const cw = payload.currentWorkspace || payload.current_workspace;
    return {
      user: {
        id: payload.id,
        name: payload.name,
        email: payload.email,
        current_workspace_id: payload.current_workspace_id,
      },
      workspaces: payload.workspaces || [],
      current_workspace: cw
        ? {
            id: cw.id,
            nombre: cw.nombre,
            slug: cw.slug,
            owner_id: cw.owner_id,
            subscription: cw.subscription,
            membership: cw.membership,
          }
        : null,
    };
  }

  return {
    user: payload.user || null,
    workspaces: payload.workspaces || [],
    current_workspace: payload.current_workspace || null,
  };
}

export function persistWorkspaceFromUser(payload) {
  const session = normalizeAuthSession(payload);
  if (!session) return null;

  localStorage.setItem(USER_KEY, JSON.stringify(session));
  localStorage.setItem(AUTH_SESSION_KEY, JSON.stringify(session));

  const cw = session.current_workspace;
  const user = session.user;

  if (cw?.id) {
    localStorage.setItem(
      'workspace_activo',
      JSON.stringify({ id: cw.id, nombre: cw.nombre, slug: cw.slug })
    );
    localStorage.setItem('current_workspace_id', String(cw.id));
  } else if (user?.current_workspace_id) {
    localStorage.setItem('current_workspace_id', String(user.current_workspace_id));
  }

  return session;
}

export function getAuthSession() {
  try {
    const raw = localStorage.getItem(AUTH_SESSION_KEY) || localStorage.getItem(USER_KEY);
    if (!raw) return null;
    return normalizeAuthSession(JSON.parse(raw));
  } catch {
    return null;
  }
}

export function clearAuthStorage() {
  localStorage.removeItem('token');
  localStorage.removeItem(USER_KEY);
  localStorage.removeItem(AUTH_SESSION_KEY);
  localStorage.removeItem('workspace_activo');
  localStorage.removeItem('current_workspace_id');
  localStorage.removeItem('token_expiration');
}

export function getActiveWorkspaceId() {
  const session = getAuthSession();
  return (
    session?.current_workspace?.id ||
    session?.user?.current_workspace_id ||
    Number(localStorage.getItem('current_workspace_id')) ||
    null
  );
}

export function isWorkspaceLocked(session = getAuthSession()) {
  const sub = session?.current_workspace?.subscription;
  if (!sub) return true;
  return Boolean(sub.is_locked) || sub.is_accessible === false;
}
