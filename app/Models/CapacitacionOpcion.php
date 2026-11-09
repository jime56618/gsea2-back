<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapacitacionOpcion extends Model
{
    protected $table = 'capacitacion_opciones';

    protected $fillable = [
        'workspace_id',
        'pregunta_id',
        'inciso',
        'texto',
        'es_correcta',
        'orden',
    ];

    /**
     * Se oculta por defecto en serializaciones JSON para nunca exponer la respuesta correcta al agente.
     * Solo el serializador administrativo habilita explícitamente makeVisible('es_correcta').
     */
    protected $hidden = [
        'es_correcta',
    ];

    protected function casts(): array
    {
        return [
            'es_correcta' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(CapacitacionPregunta::class, 'pregunta_id');
    }
}

