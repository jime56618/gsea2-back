# SaaS por promotoría (G-SEA)

## Resumen

- **Tenant:** `workspaces` (promotoría).
- **Trial:** 14 días por workspace (`config/workspace.php` → `WORKSPACE_TRIAL_DAYS`).
- **Post-trial:** bloqueo HTTP **402** (`code: workspace_locked`) hasta activar plan.
- **RBAC:** permisos fijos (`App\Support\WorkspacePermissions`) + roles sistema + roles custom por workspace.
- **Trial no aplica** al aceptar invitación (solo al crear promotoría).

## Comandos

```bash
php artisan migrate
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=PlanSeeder
php artisan db:seed --class=SystemRoleSeeder
php artisan db:seed --class=WorkspaceSubscriptionBackfillSeeder
php artisan subscriptions:expire-trials   # o vía schedule diario
```

## API clave

| Método | Ruta | Notas |
|--------|------|--------|
| POST | `/api/register` | Crea user + workspace + trial |
| GET | `/api/user` | Incluye `current_workspace.subscription` y `membership.permissions` |
| GET | `/api/workspaces/{id}/billing` | Estado suscripción (funciona bloqueado) |
| POST | `/api/workspaces/{id}/billing/activate` | Stub activación pago (requiere `billing.manage`) |
| GET | `/api/permissions` | Catálogo para editor UI |
| GET | `/api/workspaces/{id}/roles` | Roles asignables |
| PUT | `/api/roles/{id}/permissions` | Editor permisos `{ "permissions": ["polizas.view", ...] }` |
| GET | `/api/invitations/token/{token}` | Público |
| POST | `/api/invitations/accept` | Público; body: `token`, opcional `name`+`password` |

## Front (examples/)

Ver `examples/AppRoutes.example.jsx` y:

- `utils/apiClient.js` — 402/403 y token
- `utils/workspaceStorage.js` — sesión normalizada
- `context/AuthContext.jsx` — permisos, trial, cambio de workspace
- `BillingLocked.jsx`, `BillingSettings.jsx`, `TeamSettings.jsx`, `RolesEditor.jsx`, `AcceptInvitation.jsx`
- `components/TrialBanner.jsx`, `AppShell.jsx`, `WorkspaceSwitcher.jsx`

Env front: `VITE_API_URL=http://localhost:8000/api`

## Fase siguiente

- Integrar Stripe webhooks → `SubscriptionService::activatePaid`.
- Guard `agent` para portal de agentes (tabla/credenciales separadas).
