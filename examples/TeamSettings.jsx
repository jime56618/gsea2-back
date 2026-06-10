import React, { useCallback, useEffect, useState } from 'react';
import AppShell from './components/AppShell';
import { apiFetch, ApiError } from './utils/apiClient';
import { useAuth } from './context/AuthContext';
import { getActiveWorkspaceId } from './utils/workspaceStorage';
import './css/SaaS.css';

/** Invitar usuarios y listar invitaciones pendientes. */
export default function TeamSettings() {
  const { can, refresh } = useAuth();
  const [invitations, setInvitations] = useState([]);
  const [roles, setRoles] = useState([]);
  const [email, setEmail] = useState('');
  const [roleId, setRoleId] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [inviteLink, setInviteLink] = useState('');

  const workspaceId = getActiveWorkspaceId();

  const load = useCallback(async () => {
    if (!workspaceId) return;
    setLoading(true);
    try {
      const [invRes, rolesRes] = await Promise.all([
        apiFetch('/invitations?per_page=50'),
        apiFetch('/roles'),
      ]);
      setInvitations(invRes.data || invRes || []);
      setRoles(rolesRes.data || rolesRes || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [workspaceId]);

  useEffect(() => {
    load();
  }, [load]);

  const handleInvite = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    setInviteLink('');
    try {
      const inv = await apiFetch('/invitations', {
        method: 'POST',
        body: JSON.stringify({
          email,
          role_id: Number(roleId),
        }),
      });
      const base = window.location.origin;
      setInviteLink(`${base}/invitacion/${inv.token}`);
      setEmail('');
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Error al invitar');
    } finally {
      setSaving(false);
    }
  };

  if (!can('users.invite')) {
    return (
      <AppShell>
        <div className="gsea-config-page">
          <p className="gsea-error">No tienes permiso para gestionar invitaciones.</p>
        </div>
      </AppShell>
    );
  }

  return (
    <AppShell>
      <div className="gsea-config-page">
        <h1>Equipo e invitaciones</h1>
        <p className="gsea-config-sub">Invita usuarios a esta promotoría (no inician trial nuevo).</p>

        <div className="gsea-card">
          <h2 style={{ fontSize: '1rem', marginBottom: '1rem' }}>Nueva invitación</h2>
          <form onSubmit={handleInvite}>
            <label>Correo</label>
            <input
              className="gsea-input"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
            <label>Rol</label>
            <select className="gsea-select" value={roleId} onChange={(e) => setRoleId(e.target.value)} required>
              <option value="">Selecciona rol</option>
              {roles.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.nombre} {r.is_system ? '(sistema)' : ''}
                </option>
              ))}
            </select>
            {error && <p className="gsea-error">{error}</p>}
            <button type="submit" className="gsea-btn-primary" disabled={saving}>
              {saving ? 'Enviando…' : 'Crear invitación'}
            </button>
          </form>
          {inviteLink && (
            <p style={{ marginTop: '1rem', fontSize: '0.85rem', wordBreak: 'break-all' }}>
              <strong>Enlace para el invitado:</strong>
              <br />
              <a href={inviteLink}>{inviteLink}</a>
            </p>
          )}
        </div>

        <div className="gsea-card">
          <h2 style={{ fontSize: '1rem', marginBottom: '1rem' }}>Invitaciones</h2>
          {loading ? (
            <p>Cargando…</p>
          ) : (
            <table className="gsea-table">
              <thead>
                <tr>
                  <th>Email</th>
                  <th>Rol</th>
                  <th>Usada</th>
                  <th>Expira</th>
                </tr>
              </thead>
              <tbody>
                {(Array.isArray(invitations) ? invitations : []).map((inv) => (
                  <tr key={inv.id}>
                    <td>{inv.email}</td>
                    <td>{inv.role?.nombre || inv.role_id}</td>
                    <td>{inv.used ? 'Sí' : 'No'}</td>
                    <td>{inv.expires_at ? new Date(inv.expires_at).toLocaleDateString('es-MX') : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </AppShell>
  );
}
