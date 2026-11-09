<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\WorkspacePermissions as P;
use Illuminate\Database\Seeder;

// Provisionamiento explícito e independiente; no ejecutado por el Dashboard ni por migraciones.
class MetaComercialPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([P::METAS_CREATE, P::METAS_UPDATE] as $name) {
            $meta = P::catalog()[$name];
            $permission = Permission::firstOrCreate(['name' => $name], $meta);
            Role::where(function ($q) {
                $q->where('slug', 'admin')->orWhereIn('nombre', ['admin', 'Administrador']);
            })->each(fn ($role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
        }
    }
}
