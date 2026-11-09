<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapacitacionRecurso extends Model
{
    protected $table = 'capacitacion_recursos';

    protected $fillable = [
        'workspace_id',
        'curso_id',
        'leccion_id',
        'tipo',
        'titulo',
        'nombre_original',
        'mime_type',
        'size_bytes',
        'duracion_segundos',
        'disk',
        'path',
        'url_externa',
        'es_principal',
        'es_descargable',
        'orden',
    ];

    protected $hidden = [
        'disk',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duracion_segundos' => 'integer',
            'es_principal' => 'boolean',
            'es_descargable' => 'boolean',
            'orden' => 'integer',
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

    public function leccion(): BelongsTo
    {
        return $this->belongsTo(CapacitacionLeccion::class, 'leccion_id');
    }
}
