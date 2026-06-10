import React, { useState } from 'react';
import { useAuth } from './context/AuthContext';
import WorkspaceSwitcher from './components/WorkspaceSwitcher';
import { motion, AnimatePresence } from 'framer-motion';
import { Bell, Settings, X, Moon, ShieldCheck } from 'lucide-react';

function NotificationItem({ icon, title, time }) {
  return (
    <div className="flex items-start gap-3 p-1">
      <div className="mt-0.5 w-7 h-7 flex items-center justify-center bg-slate-50 rounded-lg border border-slate-100 text-slate-500">
        {icon}
      </div>
      <div>
        <p className="text-sm text-slate-700 font-medium leading-tight">{title}</p>
        <p className="text-[11px] text-slate-400 mt-0.5">{time}</p>
      </div>
    </div>
  );
}

function SettingRow({ icon, title, subtitle, action }) {
  return (
    <div className="flex justify-between items-center p-4 hover:bg-slate-50 rounded-2xl transition-all">
      <div className="flex items-center gap-3">
        <div className="text-slate-400">{icon}</div>
        <div>
          <p className="text-sm font-bold text-slate-700">{title}</p>
          <p className="text-[11px] text-slate-400">{subtitle}</p>
        </div>
      </div>
      {action}
    </div>
  );
}

export default function UserNavbar() {
  const { user, role, subscription, loading, refresh } = useAuth();
  const [activePanel, setActivePanel] = useState(null);
  const [darkMode, setDarkMode] = useState(false);

  const togglePanel = (panel) => setActivePanel(activePanel === panel ? null : panel);

  const displayName = user?.name || 'Usuario';
  const avatarSrc = `https://ui-avatars.com/api/?name=${encodeURIComponent(displayName)}&background=1E488F&color=fff`;

  if (loading) {
    return <p className="px-8 pt-8">Cargando…</p>;
  }

  return (
    <div className="flex justify-end items-center gap-3 pt-8 px-8 mb-6 relative flex-wrap">
      <WorkspaceSwitcher />

      {subscription?.status === 'trialing' && (
        <span
          style={{
            fontSize: '0.75rem',
            background: '#dbe64c',
            color: '#09324a',
            padding: '0.25rem 0.65rem',
            borderRadius: '999px',
            fontWeight: 700,
          }}
        >
          Trial: {subscription.days_left}d
        </span>
      )}

      <button
        type="button"
        onClick={() => togglePanel('settings')}
        className={`p-2.5 rounded-full ${activePanel === 'settings' ? 'bg-blue-600 text-white' : 'bg-white'}`}
      >
        <Settings size={20} />
      </button>

      <button
        type="button"
        onClick={() => togglePanel('notifications')}
        className={`p-2.5 rounded-full ${activePanel === 'notifications' ? 'bg-blue-600 text-white' : 'bg-white'}`}
      >
        <Bell size={20} />
      </button>

      <div className="flex items-center gap-3 cursor-pointer" onClick={() => togglePanel('profile')}>
        <img src={avatarSrc} alt="" className="w-10 h-10 rounded-full object-cover" />
        <div>
          <p className="font-bold text-sm">{displayName}</p>
          {role?.nombre && <p className="text-xs text-slate-500">{role.nombre}</p>}
        </div>
      </div>

      <AnimatePresence>
        {activePanel && (
          <>
            <div className="fixed inset-0 bg-black/10 z-40" onClick={() => setActivePanel(null)} />
            <motion.div
              initial={{ x: '100%' }}
              animate={{ x: 0 }}
              exit={{ x: '100%' }}
              className="fixed right-0 top-0 w-80 h-full bg-white shadow p-6 z-50"
            >
              <div className="flex justify-between mb-6">
                <h2 className="font-bold">
                  {activePanel === 'profile'
                    ? 'Perfil'
                    : activePanel === 'notifications'
                      ? 'Notificaciones'
                      : 'Configuración'}
                </h2>
                <button type="button" onClick={() => setActivePanel(null)}>
                  <X />
                </button>
              </div>

              {activePanel === 'profile' && (
                <div className="text-sm text-slate-600 space-y-2">
                  <p>
                    <strong>Email:</strong> {user?.email}
                  </p>
                  <p>
                    <strong>Rol:</strong> {role?.nombre || '—'}
                  </p>
                  <button type="button" className="gsea-btn-primary" onClick={() => refresh()}>
                    Actualizar sesión
                  </button>
                </div>
              )}

              {activePanel === 'notifications' && <p className="text-sm text-slate-500">Sin notificaciones</p>}

              {activePanel === 'settings' && (
                <div>
                  <SettingRow
                    icon={<Moon size={18} />}
                    title="Modo oscuro"
                    subtitle="Próximamente"
                    action={
                      <button type="button" onClick={() => setDarkMode(!darkMode)}>
                        {darkMode ? 'ON' : 'OFF'}
                      </button>
                    }
                  />
                  <SettingRow icon={<ShieldCheck size={18} />} title="Seguridad" subtitle="Sesión activa" action={null} />
                </div>
              )}
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
}
