<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'nombre',
        'slug',
        'workspace_id',
        'is_system',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')
            ->withTimestamps();
    }

    public function workspaceUsers(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class);
    }

    public function hasPermission(string $name): bool
    {
        if ($this->relationLoaded('permissions')) {
            return $this->permissions->contains('name', $name);
        }

        return $this->permissions()->where('name', $name)->exists();
    }

    public function syncPermissionNames(array $names): void
    {
        $ids = Permission::query()->whereIn('name', $names)->pluck('id');
        $this->permissions()->sync($ids);
    }
}
