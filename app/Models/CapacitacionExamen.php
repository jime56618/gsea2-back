<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapacitacionExamen extends Model
{
    protected $table = 'capacitacion_examenes';

    protected $fillable = [
        'workspace_id',
        'curso_id',
        'modulo_id',
        'leccion_id',
        'titulo',
        'descripcion',
        'instrucciones',
        'calificacion_minima',
        'max_intentos',
        'tiempo_limite_minutos',
        'es_obligatorio',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'calificacion_minima' => 'float',
            'max_intentos' => 'integer',
            'tiempo_limite_minutos' => 'integer',
            'es_obligatorio' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
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

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(CapacitacionModulo::class, 'modulo_id');
    }

    public function leccion(): BelongsTo
    {
        return $this->belongsTo(CapacitacionLeccion::class, 'leccion_id');
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(CapacitacionPregunta::class, 'examen_id')->orderBy('orden')->orderBy('id');
    }

    public function intentos(): HasMany
    {
        return $this->hasMany(CapacitacionIntentoExamen::class, 'examen_id')->orderByDesc('numero_intento');
    }
}

