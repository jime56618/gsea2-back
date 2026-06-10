<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workspace extends Model
{
    protected $table = 'workspaces';

    protected $fillable = [
        'nombre',
        'slug',
        'plan_id',
        'owner_id',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(WorkspaceSubscription::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function workspaceUsers(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class);
    }

    public function contratantes()
    {
        return $this->hasMany(Contratante::class);
    }

    public function polizas()
    {
        return $this->hasMany(Poliza::class);
    }

    public function agentes()
    {
        return $this->hasMany(AgenteWorkspace::class);
    }

    public function tramites()
    {
        return $this->hasMany(Tramite::class);
    }

    public function cobranzaCuotas()
    {
        return $this->hasMany(CobranzaCuota::class);
    }

    public function customRoles(): HasMany
    {
        return $this->hasMany(Role::class);
    }
}
