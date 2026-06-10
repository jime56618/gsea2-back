import React, { useCallback, useEffect, useState } from 'react';
import AppShell from './components/AppShell';
import { apiFetch, ApiError } from './utils/apiClient';
import { useAuth } from './context/AuthContext';
import { getActiveWorkspaceId } from './utils/workspaceStorage';
import './css/SaaS.css';

/** Editor de roles custom y permisos (checkboxes). */
export default function RolesEditor() {
  const { can, currentWorkspace } = useAuth();
  const workspaceId = getActiveWorkspaceId();
  const [roles, setRoles] = useState([]);
  const [catalog, setCatalog] = useState([]);
  const [selectedRole, setSelectedRole] = useState(null);
  const [selectedPerms, setSelectedPerms] = useState([]);
  const [newName, setNewName] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [msg, setMsg] = useState('');

  const load = useCallback(async () => {
    if (!workspaceId) return;
    setLoading(true);
    try {
      const [rolesRes, permRes] = await Promise.all([
        apiFetch(`/workspaces/${workspaceId}/roles`),
        apiFetch('/permissions'),
      ]);
      setRoles(rolesRes.data || []);
      const perms = permRes.permissions || [];
      setCatalog(perms);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [workspaceId]);

  useEffect(() => {
    load();
  }, [load]);

  const selectRole = (role) => {
    setSelectedRole(role);
    const names = (role.permissions || []).map((p) => p.name);
    setSelectedPerms(names);
    setMsg('');
  };

  const togglePerm = (name) => {
    setSelectedPerms((prev) =>
      prev.includes(name) ? prev.filter((p) => p !== name) : [...prev, name]
    );
  };

  const savePermissions = async () => {
    if (!selectedRole || selectedRole.is_system && selectedRole.slug === 'admin') {
      setError('No se puede editar el rol Administrador.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await apiFetch(`/roles/${selectedRole.id}/permissions`, {
        method: 'PUT',
        body: JSON.stringify({ permissions: selectedPerms }),
      });
      setMsg('Permisos guardados.');
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Error al guardar');
    } finally {
      setSaving(false);
    }
  };

  const createRole = async (e) => {
    e.preventDefault();
    if (!newName.trim()) return;
    setSaving(true);
    try {
      await apiFetch(`/workspaces/${workspaceId}/roles`, {
        method: 'POST',
        body: JSON.stringify({ nombre: newName.trim(), permissions: [] }),
      });
      setNewName('');
      await load();
      setMsg('Rol creado.');
    } catch (e) {
      setError(e.message);
    } finally {
      setSaving(false);
    }
  };

  if (!can('roles.manage')) {
    return (
      <AppShell>
        <div className="gsea-config-page">
          <p className="gsea-error">No tienes permiso para gestionar roles.</p>
        </div>
      </AppShell>
    );
  }

  return (
    <AppShell>
      <div className="gsea-config-page">
        <h1>Roles y permisos</h1>
        <p className="gsea-config-sub">{currentWorkspace?.nombre}</p>

        <div className="gsea-card">
          <h2 style={{ fontSize: '1rem' }}>Nuevo rol personalizado</h2>
          <form onSubmit={createRole} style={{ display: 'flex', gap: '0.5rem', marginTop: '0.75rem' }}>
            <input
              className="gsea-input"
              style={{ marginBottom: 0 }}
              placeholder="Nombre del rol"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
            />
            <button type="submit" className="gsea-btn-primary" disabled={saving}>
              Crear
            </button>
          </form>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '240px 1fr', gap: '1rem' }}>
          <div className="gsea-card">
            <h2 style={{ fontSize: '1rem', marginBottom: '0.75rem' }}>Roles</h2>
            {loading ? (
              <p>Cargando…</p>
            ) : (
              <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                {roles.map((r) => (
                  <li key={r.id}>
                    <button
                      type="button"
                      onClick={() => selectRole(r)}
                      style={{
                        width: '100%',
                        textAlign: 'left',
                        padding: '0.5rem',
                        marginBottom: '0.25rem',
                        borderRadius: '0.35rem',
                        border: selectedRole?.id === r.id ? '2px solid #1e488f' : '1px solid #e2e8f0',
                        background: '#fff',
                        cursor: 'pointer',
                      }}
                    >
                      {r.nombre}
                      {r.is_system && <span style={{ fontSize: '0.7rem', color: '#94a3b8' }}> · sistema</span>}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="gsea-card">
            {selectedRole ? (
              <>
                <h2 style={{ fontSize: '1rem' }}>
                  Permisos: {selectedRole.nombre}
                  {selectedRole.is_system && selectedRole.slug === 'admin' && (
                    <span style={{ fontSize: '0.8rem', color: '#94a3b8' }}> (solo lectura)</span>
                  )}
                </h2>
                <div className="gsea-perm-grid" style={{ marginTop: '1rem' }}>
                  {catalog.map((p) => (
                    <label key={p.name} className="gsea-perm-item">
                      <input
                        type="checkbox"
                        checked={selectedPerms.includes(p.name)}
                        disabled={selectedRole.is_system && selectedRole.slug === 'admin'}
                        onChange={() => togglePerm(p.name)}
                      />
                      <span>
                        <strong>{p.name}</strong>
                        <br />
                        <span style={{ color: '#94a3b8' }}>{p.description}</span>
                      </span>
                    </label>
                  ))}
                </div>
                {error && <p className="gsea-error">{error}</p>}
                {msg && <p style={{ color: '#16a34a' }}>{msg}</p>}
                {!(selectedRole.is_system && selectedRole.slug === 'admin') && (
                  <button
                    type="button"
                    className="gsea-btn-primary"
                    style={{ marginTop: '1rem' }}
                    disabled={saving}
                    onClick={savePermissions}
                  >
                    {saving ? 'Guardando…' : 'Guardar permisos'}
                  </button>
                )}
              </>
            ) : (
              <p style={{ color: '#64748b' }}>Selecciona un rol para editar permisos.</p>
            )}
          </div>
        </div>
      </div>
    </AppShell>
  );
}
