<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapacitacionPregunta extends Model
{
    protected $table = 'capacitacion_preguntas';

    protected $fillable = [
        'workspace_id',
        'examen_id',
        'tipo',
        'enunciado',
        'explicacion',
        'puntaje',
        'orden',
    ];

    protected $hidden = [
        'explicacion',
    ];

    protected function casts(): array
    {
        return [
            'puntaje' => 'float',
            'orden' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(CapacitacionExamen::class, 'examen_id');
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(CapacitacionOpcion::class, 'pregunta_id')->orderBy('orden')->orderBy('id');
    }
}
