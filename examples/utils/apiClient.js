import { API_URL, TOKEN_KEY } from './constants';
import { getAuthSession, isWorkspaceLocked } from './workspaceStorage';

export class ApiError extends Error {
  constructor(message, status, body) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
  }
}

let onWorkspaceLocked = null;

/** Registrar handler global (ej. navigate a /billing-locked). */
export function setWorkspaceLockedHandler(fn) {
  onWorkspaceLocked = fn;
}

export function authHeaders(extra = {}) {
  const token = localStorage.getItem(TOKEN_KEY);
  return {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...extra,
  };
}

/**
 * Fetch al API con manejo de 402 (workspace bloqueado) y 403 (permiso).
 */
export async function apiFetch(path, options = {}) {
  const url = path.startsWith('http') ? path : `${API_URL}${path.startsWith('/') ? path : `/${path}`}`;

  const isFormData = options.body instanceof FormData;
  const headers = authHeaders(
    options.body && !isFormData ? { 'Content-Type': 'application/json' } : {}
  );

  const res = await fetch(url, {
    ...options,
    headers: { ...headers, ...options.headers },
  });

  let data = null;
  const text = await res.text();
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = { message: text };
    }
  }

  if (res.status === 402 && data?.code === 'workspace_locked') {
    if (onWorkspaceLocked) onWorkspaceLocked(data);
    else if (typeof window !== 'undefined' && !window.location.pathname.includes('billing-locked')) {
      window.location.href = '/billing-locked';
    }
    throw new ApiError(data.message || 'Suscripción bloqueada', 402, data);
  }

  if (!res.ok) {
    throw new ApiError(
      data?.message || `Error ${res.status}`,
      res.status,
      data
    );
  }

  return data;
}

export async function refreshAuthSession() {
  const { persistWorkspaceFromUser } = await import('./workspaceStorage.js');
  const data = await apiFetch('/user', { method: 'GET' });
  return persistWorkspaceFromUser(data);
}

/**
 * Compatible con módulos que usaban fetchJson(`${API_URL}/ruta`).
 * Acepta path relativo (/polizas) o URL completa que contenga /api/.
 */
export async function fetchJson(urlOrPath, options = {}) {
  let path = urlOrPath;
  if (typeof path === 'string' && path.includes('/api/')) {
    path = path.split('/api')[1] || path;
  }
  if (typeof path === 'string' && path.startsWith(API_URL)) {
    path = path.slice(API_URL.length);
  }
  if (!path.startsWith('/')) path = `/${path}`;
  return apiFetch(path, options);
}

export function canAccessAppRoutes() {
  const token = localStorage.getItem(TOKEN_KEY);
  if (!token) return false;
  const session = getAuthSession();
  if (!session) return true;
  return !isWorkspaceLocked(session);
}
