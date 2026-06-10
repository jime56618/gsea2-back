<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\WorkspacePermissions as P;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Roles plantilla (workspace_id null). Se asignan vía workspace_user.role_id.
 */
class SystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        $all = Permission::pluck('name')->all();

        $roles = [
            'admin' => [
                'nombre' => 'Administrador',
                'description' => 'Acceso total en la promotoría',
                'permissions' => $all,
            ],
            'operador' => [
                'nombre' => 'Operador',
                'description' => 'Operación diaria sin gestión de roles ni facturación',
                'permissions' => array_values(array_diff($all, [
                    P::ROLES_MANAGE,
                    P::BILLING_MANAGE,
                    P::WORKSPACES_MANAGE,
                ])),
            ],
            'cobranza' => [
                'nombre' => 'Cobranza',
                'description' => 'Cobranza, calendario y consulta de pólizas',
                'permissions' => [
                    P::DASHBOARD_VIEW,
                    P::CALENDAR_VIEW,
                    P::COBRANZA_VIEW,
                    P::COBRANZA_MANAGE,
                    P::POLIZAS_VIEW,
                    P::CONTRATANTES_VIEW,
                    P::AGENTES_VIEW,
                ],
            ],
            'solo_lectura' => [
                'nombre' => 'Solo lectura',
                'description' => 'Consulta sin modificar datos',
                'permissions' => array_filter($all, fn ($p) => str_ends_with($p, '.view')),
            ],
        ];

        // Compatibilidad con roles legacy member / viewer
        $legacyMap = ['member' => 'operador', 'viewer' => 'solo_lectura'];

        foreach ($legacyMap as $legacySlug => $targetSlug) {
            $legacy = Role::where('nombre', $legacySlug)->orWhere('slug', $legacySlug)->first();
            if ($legacy && ! Role::where('slug', $targetSlug)->whereNull('workspace_id')->exists()) {
                $legacy->update([
                    'slug' => $targetSlug,
                    'nombre' => $roles[$targetSlug]['nombre'],
                    'is_system' => true,
                    'description' => $roles[$targetSlug]['description'],
                ]);
                $legacy->syncPermissionNames($roles[$targetSlug]['permissions']);
            }
        }

        foreach ($roles as $slug => $config) {
            $role = Role::updateOrCreate(
                [
                    'workspace_id' => null,
                    'slug' => $slug,
                ],
                [
                    'nombre' => $config['nombre'],
                    'is_system' => true,
                    'description' => $config['description'],
                ]
            );

            // Migrar filas que aún apuntan por nombre antiguo
            Role::query()
                ->whereNull('workspace_id')
                ->where('nombre', $slug)
                ->whereNull('slug')
                ->update(['slug' => $slug, 'is_system' => true]);

            $role->syncPermissionNames($config['permissions']);
        }

        // Asegurar admin por nombre legacy
        $admin = Role::query()
            ->where(function ($q) {
                $q->where('slug', 'admin')->orWhere('nombre', 'admin');
            })
            ->whereNull('workspace_id')
            ->first();

        if ($admin) {
            $admin->update([
                'slug' => 'admin',
                'nombre' => 'Administrador',
                'is_system' => true,
                'description' => 'Acceso total en la promotoría',
            ]);
            $admin->syncPermissionNames($all);
        }
    }
}
