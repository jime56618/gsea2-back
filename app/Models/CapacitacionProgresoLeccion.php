<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapacitacionProgresoLeccion extends Model
{
    protected $table = 'capacitacion_progreso_lecciones';

    public const ESTADO_EN_PROGRESO = 'en_progreso';
    public const ESTADO_COMPLETADA = 'completada';

    protected $fillable = [
        'workspace_id',
        'asignacion_id',
        'curso_id',
        'leccion_id',
        'leccion_titulo_snapshot',
        'estado',
        'segundos_vistos',
        'duracion_total_segundos',
        'porcentaje_visto',
        'completada_at',
        'ultimo_acceso_at',
    ];

    protected function casts(): array
    {
        return [
            'segundos_vistos' => 'integer',
            'duracion_total_segundos' => 'integer',
            'porcentaje_visto' => 'float',
            'completada_at' => 'datetime',
            'ultimo_acceso_at' => 'datetime',
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

    public function leccion(): BelongsTo
    {
        return $this->belongsTo(CapacitacionLeccion::class, 'leccion_id');
    }
}

