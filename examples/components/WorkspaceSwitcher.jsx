import React, { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import '../css/SaaS.css';

/** Selector de promotoría cuando el usuario pertenece a varias. */
export default function WorkspaceSwitcher() {
  const { workspaces, currentWorkspace, switchWorkspace, subscription } = useAuth();
  const [busy, setBusy] = useState(false);

  if (!workspaces?.length) return null;

  const handleChange = async (e) => {
    const id = e.target.value;
    if (!id || String(currentWorkspace?.id) === id) return;
    setBusy(true);
    try {
      await switchWorkspace(Number(id));
      window.location.reload();
    } catch (err) {
      console.error(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="gsea-workspace-switcher" style={{ marginRight: '0.75rem' }}>
      <select
        value={currentWorkspace?.id || ''}
        onChange={handleChange}
        disabled={busy || workspaces.length < 2}
        title="Promotoría activa"
      >
        {workspaces.map((ws) => (
          <option key={ws.id} value={ws.id}>
            {ws.nombre}
            {ws.subscription?.status === 'trialing' ? ` (trial ${ws.subscription.days_left}d)` : ''}
          </option>
        ))}
      </select>
    </div>
  );
}
