<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapacitacionRespuestaIntento extends Model
{
    protected $table = 'capacitacion_respuestas_intento';

    protected $fillable = [
        'workspace_id',
        'intento_id',
        'pregunta_id',
        'opcion_id',
        'enunciado_snapshot',
        'inciso_seleccionado_snapshot',
        'opcion_texto_snapshot',
        'es_correcta',
        'puntaje_obtenido',
    ];

    protected function casts(): array
    {
        return [
            'es_correcta' => 'boolean',
            'puntaje_obtenido' => 'float',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function intento(): BelongsTo
    {
        return $this->belongsTo(CapacitacionIntentoExamen::class, 'intento_id');
    }

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(CapacitacionPregunta::class, 'pregunta_id');
    }

    public function opcion(): BelongsTo
    {
        return $this->belongsTo(CapacitacionOpcion::class, 'opcion_id');
    }
}

