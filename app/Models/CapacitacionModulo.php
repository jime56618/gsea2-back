<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapacitacionModulo extends Model
{
    protected $table = 'capacitacion_modulos';

    protected $fillable = [
        'workspace_id',
        'curso_id',
        'titulo',
        'descripcion',
        'orden',
        'duracion_minutos',
        'es_obligatorio',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'duracion_minutos' => 'integer',
            'es_obligatorio' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(CapacitacionCurso::class, 'curso_id');
    }

    public function lecciones(): HasMany
    {
        return $this->hasMany(CapacitacionLeccion::class, 'modulo_id')->orderBy('orden')->orderBy('id');
    }

    public function examenes(): HasMany
    {
        return $this->hasMany(CapacitacionExamen::class, 'modulo_id')->orderBy('orden')->orderBy('id');
    }
}

