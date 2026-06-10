/**
 * Permisos alineados con App\Support\WorkspacePermissions (backend).
 */
export const PERMISSIONS = {
  DASHBOARD_VIEW: 'dashboard.view',
  TRAMITES_VIEW: 'tramites.view',
  TRAMITES_MANAGE: 'tramites.manage',
  AGENTES_VIEW: 'agentes.view',
  AGENTES_MANAGE: 'agentes.manage',
  CONTRATANTES_VIEW: 'contratantes.view',
  CONTRATANTES_MANAGE: 'contratantes.manage',
  POLIZAS_VIEW: 'polizas.view',
  POLIZAS_MANAGE: 'polizas.manage',
  COBRANZA_VIEW: 'cobranza.view',
  COBRANZA_MANAGE: 'cobranza.manage',
  CALENDAR_VIEW: 'calendar.view',
  USERS_INVITE: 'users.invite',
  USERS_MANAGE: 'users.manage',
  ROLES_MANAGE: 'roles.manage',
  BILLING_MANAGE: 'billing.manage',
  WORKSPACES_MANAGE: 'workspaces.manage',
};

/** Rutas del sidebar: se muestran si el usuario tiene el permiso indicado. */
export const MENU_ITEMS = [
  { path: '/dashboard', label: 'Dashboard', permission: PERMISSIONS.DASHBOARD_VIEW, group: 'main' },
  { path: '/tramites', label: 'Trámites', permission: PERMISSIONS.TRAMITES_VIEW, group: 'main' },
  { path: '/agentes', label: 'Agentes', permission: PERMISSIONS.AGENTES_VIEW, group: 'main' },
  { path: '/clientes', label: 'Clientes', permission: PERMISSIONS.CONTRATANTES_VIEW, group: 'main' },
  { path: '/seguimiento-polizas', label: 'Seguimiento de Pólizas', permission: PERMISSIONS.POLIZAS_VIEW, group: 'main' },
  { path: '/seguimiento-cobranza', label: 'Seguimiento de Cobranza', permission: PERMISSIONS.COBRANZA_VIEW, group: 'main' },
  { path: '/calendario', label: 'Calendario', permission: PERMISSIONS.CALENDAR_VIEW, group: 'main' },
  { path: '/capacitacion', label: 'Capacitación', permission: PERMISSIONS.DASHBOARD_VIEW, group: 'main' },
];

export const ADMIN_MENU_ITEMS = [
  { path: '/configuracion/equipo', label: 'Equipo e invitaciones', permission: PERMISSIONS.USERS_INVITE },
  { path: '/configuracion/roles', label: 'Roles y permisos', permission: PERMISSIONS.ROLES_MANAGE },
  { path: '/configuracion/facturacion', label: 'Facturación', permission: PERMISSIONS.BILLING_MANAGE },
];

export function getPermissionList(session) {
  return session?.current_workspace?.membership?.permissions || [];
}

export function hasPermission(session, permission) {
  if (!permission) return true;
  const list = getPermissionList(session);
  return list.includes(permission);
}

export function filterMenuByPermissions(items, session) {
  return items.filter((item) => hasPermission(session, item.permission));
}
