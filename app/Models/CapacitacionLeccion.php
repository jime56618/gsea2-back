<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapacitacionLeccion extends Model
{
    protected $table = 'capacitacion_lecciones';

    protected $fillable = [
        'workspace_id',
        'curso_id',
        'modulo_id',
        'titulo',
        'resumen',
        'tipo',
        'contenido_html',
        'video_proveedor',
        'video_url',
        'duracion_minutos',
        'duracion_segundos',
        'orden',
        'es_obligatoria',
    ];

    protected function casts(): array
    {
        return [
            'duracion_minutos' => 'integer',
            'duracion_segundos' => 'integer',
            'orden' => 'integer',
            'es_obligatoria' => 'boolean',
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

    public function recursos(): HasMany
    {
        return $this->hasMany(CapacitacionRecurso::class, 'leccion_id')->orderBy('orden')->orderBy('id');
    }

    public function progresos(): HasMany
    {
        return $this->hasMany(CapacitacionProgresoLeccion::class, 'leccion_id');
    }
}
