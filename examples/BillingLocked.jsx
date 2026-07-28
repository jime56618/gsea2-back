import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiFetch } from './utils/apiClient';
import { useAuth } from './context/AuthContext';
import { getActiveWorkspaceId } from './utils/workspaceStorage';
import './css/SaaS.css';

/**
 * Pantalla cuando el trial venció (HTTP 402). Permite activar plan (stub) si tiene billing.manage o es owner.
 */
export default function BillingLocked() {
  const { refresh, logout, can, subscription, currentWorkspace, user } = useAuth();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [debug, setDebug] = useState('');

  const workspaceId =
    getActiveWorkspaceId() ||
    currentWorkspace?.id ||
    user?.current_workspace_id ||
    null;

  const isOwner =
    currentWorkspace?.owner_id != null &&
    user?.id != null &&
    Number(currentWorkspace.owner_id) === Number(user.id);

  const canActivate = can('billing.manage') || isOwner;

  const handleActivate = async () => {
    setError('');
    setDebug('');

    if (!workspaceId) {
      setError(
        'No se encontró el ID de la promotoría. Cierra sesión, vuelve a entrar e intenta de nuevo.'
      );
      setDebug('workspaceId=null');
      return;
    }

    setLoading(true);
    try {
      const res = await apiFetch(`/workspaces/${workspaceId}/billing/activate`, {
        method: 'POST',
        body: JSON.stringify({}),
      });

      const next = await refresh();
      const stillLocked =
        next?.current_workspace?.subscription?.is_locked === true ||
        next?.current_workspace?.subscription?.is_accessible === false;

      if (stillLocked) {
        setError(
          'El plan se activó en el servidor, pero la sesión sigue bloqueada. Recarga la página (F5).'
        );
        setDebug(JSON.stringify(res?.subscription || res, null, 2));
        return;
      }

      navigate('/dashboard', { replace: true });
    } catch (e) {
      const status = e?.status ? ` [${e.status}]` : '';
      const detail =
        e?.body?.message ||
        e?.message ||
        'No se pudo activar el plan';
      setError(`${detail}${status}`);
      setDebug(
        `workspaceId=${workspaceId} · canActivate=${canActivate} · ${JSON.stringify(e?.body || {})}`
      );
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="gsea-billing-page">
      <div className="gsea-billing-card">
        <h1>Tu periodo de prueba terminó</h1>
        <p>
          La promotoría está bloqueada hasta que actives un plan de pago. Contacta al administrador
          de tu cuenta o activa la suscripción si tienes permisos.
        </p>
        {subscription?.trial_ends_at && (
          <p style={{ fontSize: '0.85rem', color: '#94a3b8' }}>
            Trial finalizó: {new Date(subscription.trial_ends_at).toLocaleDateString('es-MX')}
          </p>
        )}
        {workspaceId && (
          <p style={{ fontSize: '0.8rem', color: '#64748b' }}>
            Promotoría ID: {workspaceId}
            {subscription?.status ? ` · Estado: ${subscription.status}` : ''}
          </p>
        )}
        {error && <p className="gsea-error">{error}</p>}
        {debug && (
          <pre
            style={{
              fontSize: '0.7rem',
              color: '#94a3b8',
              whiteSpace: 'pre-wrap',
              textAlign: 'left',
              maxHeight: 120,
              overflow: 'auto',
            }}
          >
            {debug}
          </pre>
        )}
        {canActivate ? (
          <button type="button" className="gsea-billing-btn" disabled={loading} onClick={handleActivate}>
            {loading ? 'Activando…' : 'Activar plan (demo)'}
          </button>
        ) : (
          <p style={{ fontSize: '0.9rem', color: '#64748b' }}>
            No tienes permiso de facturación. Pide a un administrador que active el plan, o ejecuta
            en el servidor: <code>php artisan billing:activate</code>
          </p>
        )}
        <button
          type="button"
          className="gsea-billing-btn gsea-billing-btn--secondary"
          onClick={async () => {
            setError('');
            await refresh();
          }}
        >
          Revisar estado
        </button>
        <button
          type="button"
          className="gsea-billing-btn gsea-billing-btn--secondary"
          onClick={logout}
          style={{ marginTop: '0.5rem' }}
        >
          Cerrar sesión
        </button>
        {canActivate && (
          <Link to="/configuracion/facturacion" style={{ display: 'block', marginTop: '1rem', fontSize: '0.85rem' }}>
            Ir a configuración de facturación
          </Link>
        )}
      </div>
    </div>
  );
}
