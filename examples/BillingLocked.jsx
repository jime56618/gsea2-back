import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiFetch } from './utils/apiClient';
import { useAuth } from './context/AuthContext';
import { getActiveWorkspaceId } from './utils/workspaceStorage';
import './css/SaaS.css';

/**
 * Pantalla cuando el trial venció (HTTP 402). Permite activar plan (stub) si tiene billing.manage.
 */
export default function BillingLocked() {
  const { refresh, logout, can, subscription } = useAuth();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const workspaceId = getActiveWorkspaceId();

  const handleActivate = async () => {
    if (!workspaceId) return;
    setLoading(true);
    setError('');
    try {
      await apiFetch(`/workspaces/${workspaceId}/billing/activate`, { method: 'POST' });
      await refresh();
      navigate('/dashboard', { replace: true });
    } catch (e) {
      setError(e.message || 'No se pudo activar el plan');
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
        {error && <p className="gsea-error">{error}</p>}
        {can('billing.manage') ? (
          <button type="button" className="gsea-billing-btn" disabled={loading} onClick={handleActivate}>
            {loading ? 'Activando…' : 'Activar plan (demo)'}
          </button>
        ) : (
          <p style={{ fontSize: '0.9rem', color: '#64748b' }}>
            No tienes permiso de facturación. Pide a un administrador que active el plan.
          </p>
        )}
        <button type="button" className="gsea-billing-btn gsea-billing-btn--secondary" onClick={() => refresh()}>
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
        {can('billing.manage') && (
          <Link to="/configuracion/facturacion" style={{ display: 'block', marginTop: '1rem', fontSize: '0.85rem' }}>
            Ir a configuración de facturación
          </Link>
        )}
      </div>
    </div>
  );
}
