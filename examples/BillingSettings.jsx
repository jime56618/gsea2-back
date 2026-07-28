import React, { useEffect, useState } from 'react';
import AppShell from './components/AppShell';
import { apiFetch } from './utils/apiClient';
import { useAuth } from './context/AuthContext';
import { getActiveWorkspaceId } from './utils/workspaceStorage';
import './css/SaaS.css';

/** Estado de suscripción y activación manual (hasta integrar Stripe). */
export default function BillingSettings() {
  const { subscription, refresh, can, currentWorkspace, user } = useAuth();
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activating, setActivating] = useState(false);
  const [msg, setMsg] = useState('');

  const workspaceId = getActiveWorkspaceId() || currentWorkspace?.id || null;
  const isOwner =
    currentWorkspace?.owner_id != null &&
    user?.id != null &&
    Number(currentWorkspace.owner_id) === Number(user.id);
  const canManageBilling = can('billing.manage') || isOwner;

  useEffect(() => {
    if (!workspaceId) {
      setLoading(false);
      return;
    }
    apiFetch(`/workspaces/${workspaceId}/billing`)
      .then(setDetail)
      .catch((e) => setMsg(e.message))
      .finally(() => setLoading(false));
  }, [workspaceId]);

  const handleActivate = async () => {
    if (!workspaceId) {
      setMsg('No hay workspace activo.');
      return;
    }
    setActivating(true);
    setMsg('');
    try {
      await apiFetch(`/workspaces/${workspaceId}/billing/activate`, {
        method: 'POST',
        body: JSON.stringify({}),
      });
      await refresh();
      const d = await apiFetch(`/workspaces/${workspaceId}/billing`);
      setDetail(d);
      setMsg('Plan activado correctamente.');
    } catch (e) {
      setMsg(e.body?.message || e.message);
    } finally {
      setActivating(false);
    }
  };

  if (!canManageBilling) {
    return (
      <AppShell>
        <div className="gsea-config-page">
          <p className="gsea-error">No tienes permiso para ver facturación.</p>
        </div>
      </AppShell>
    );
  }

  const sub = detail?.subscription || subscription;

  return (
    <AppShell>
      <div className="gsea-config-page">
        <h1>Facturación</h1>
        <p className="gsea-config-sub">Promotoría: {currentWorkspace?.nombre}</p>

        {loading ? (
          <p>Cargando…</p>
        ) : (
          <div className="gsea-card">
            <p>
              <strong>Estado:</strong> {sub?.status || '—'}
            </p>
            <p>
              <strong>Acceso:</strong> {sub?.is_accessible ? 'Activo' : 'Bloqueado'}
            </p>
            {sub?.days_left != null && sub.status === 'trialing' && (
              <p>
                <strong>Días de prueba restantes:</strong> {sub.days_left}
              </p>
            )}
            {sub?.plan && (
              <p>
                <strong>Plan:</strong> {sub.plan.nombre} (${sub.plan.precio_mensual}/mes)
              </p>
            )}
            {sub?.usage && (
              <p style={{ marginTop: '1rem', fontSize: '0.85rem', color: '#64748b' }}>
                Uso: {sub.usage.users} usuarios · {sub.usage.polizas} pólizas · {sub.usage.agentes}{' '}
                agentes
              </p>
            )}
            {msg && <p className={msg.includes('correctamente') ? '' : 'gsea-error'}>{msg}</p>}
            {(sub?.is_locked || !sub?.is_accessible) && (
              <button
                type="button"
                className="gsea-btn-primary"
                style={{ marginTop: '1rem' }}
                disabled={activating}
                onClick={handleActivate}
              >
                {activating ? 'Procesando…' : 'Activar plan (demo sin Stripe)'}
              </button>
            )}
          </div>
        )}
      </div>
    </AppShell>
  );
}
