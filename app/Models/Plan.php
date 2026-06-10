<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'planes';

    protected $fillable = [
        'nombre',
        'slug',
        'precio_mensual',
        'trial_days',
        'limits',
        'features',
        'is_public',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'precio_mensual' => 'decimal:2',
            'trial_days' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public function workspaces()
    {
        return $this->hasMany(Workspace::class);
    }
}
