import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { TOKEN_KEY } from './utils/constants';
import { getAuthSession, isWorkspaceLocked } from './utils/workspaceStorage';

/** Rutas accesibles aunque el workspace esté bloqueado (alineado con backend). */
const LOCKED_ALLOWED = ['/billing-locked', '/configuracion/facturacion'];

export default function ProtectedRoute({ children }) {
  const location = useLocation();
  const token = localStorage.getItem(TOKEN_KEY);

  if (!token) {
    return <Navigate to="/register" replace state={{ from: location }} />;
  }

  const session = getAuthSession();
  const locked = isWorkspaceLocked(session);
  const path = location.pathname;

  if (locked && !LOCKED_ALLOWED.some((p) => path.startsWith(p))) {
    return <Navigate to="/billing-locked" replace />;
  }

  return children;
}
