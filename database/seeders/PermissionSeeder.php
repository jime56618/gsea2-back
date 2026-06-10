<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\WorkspacePermissions;
use Illuminate\Database\Seeder;

/** Catálogo fijo de permisos (ver WorkspacePermissions). */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (WorkspacePermissions::catalog() as $name => $meta) {
            Permission::updateOrCreate(
                ['name' => $name],
                [
                    'group' => $meta['group'],
                    'description' => $meta['description'],
                ]
            );
        }
    }
}
