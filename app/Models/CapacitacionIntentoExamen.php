<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapacitacionIntentoExamen extends Model
{
    protected $table = 'capacitacion_intentos_examen';

    protected $fillable = [
        'workspace_id',
        'asignacion_id',
        'curso_id',
        'examen_id',
        'user_id',
        'examen_titulo_snapshot',
        'numero_intento',
        'estado',
        'puntaje_obtenido',
        'puntaje_maximo',
        'calificacion',
        'calificacion_minima_snapshot',
        'aprobado',
        'iniciado_at',
        'enviado_at',
    ];

    protected function casts(): array
    {
        return [
            'numero_intento' => 'integer',
            'puntaje_obtenido' => 'float',
            'puntaje_maximo' => 'float',
            'calificacion' => 'float',
            'calificacion_minima_snapshot' => 'float',
            'aprobado' => 'boolean',
            'iniciado_at' => 'datetime',
            'enviado_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(CapacitacionAsignacion::class, 'asignacion_id');
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(CapacitacionCurso::class, 'curso_id');
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(CapacitacionExamen::class, 'examen_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(CapacitacionRespuestaIntento::class, 'intento_id');
    }
}

