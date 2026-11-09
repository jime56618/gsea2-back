<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CapacitacionAsignacion extends Model
{
    protected $table = 'capacitacion_asignaciones';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_EN_PROGRESO = 'en_progreso';
    public const ESTADO_COMPLETADO = 'completado';

    protected $fillable = [
        'workspace_id',
        'curso_id',
        'user_id',
        'agente_workspace_id',
        'assigned_by',
        'destinatario_nombre',
        'destinatario_email',
        'estado',
        'porcentaje_avance',
        'calificacion_final',
        'aprobado',
        'ultima_leccion_id',
        'fecha_asignacion',
        'fecha_limite',
        'fecha_inicio',
        'fecha_completado',
    ];

    protected function casts(): array
    {
        return [
            'porcentaje_avance' => 'float',
            'calificacion_final' => 'float',
            'aprobado' => 'boolean',
            'fecha_asignacion' => 'datetime',
            'fecha_limite' => 'date',
            'fecha_inicio' => 'datetime',
            'fecha_completado' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agenteWorkspace(): BelongsTo
    {
        return $this->belongsTo(AgenteWorkspace::class, 'agente_workspace_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function ultimaLeccion(): BelongsTo
    {
        return $this->belongsTo(CapacitacionLeccion::class, 'ultima_leccion_id');
    }

    public function progresoLecciones(): HasMany
    {
        return $this->hasMany(CapacitacionProgresoLeccion::class, 'asignacion_id');
    }

    public function progresosLecciones(): HasMany
    {
        return $this->hasMany(CapacitacionProgresoLeccion::class, 'asignacion_id');
    }

    public function intentosExamen(): HasMany
    {
        return $this->hasMany(CapacitacionIntentoExamen::class, 'asignacion_id')->orderByDesc('numero_intento');
    }

    public function constancia(): HasOne
    {
        return $this->hasOne(CapacitacionConstancia::class, 'asignacion_id');
    }
}
