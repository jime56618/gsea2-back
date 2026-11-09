<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapacitacionConstancia extends Model
{
    protected $table = 'capacitacion_constancias';

    protected $fillable = [
        'workspace_id',
        'asignacion_id',
        'curso_id',
        'user_id',
        'agente_workspace_id',
        'folio',
        'codigo_verificacion',
        'nombre_agente',
        'nombre_curso',
        'nombre_promotoria',
        'duracion_texto',
        'calificacion_obtenida',
        'responsable_nombre',
        'responsable_cargo',
        'fecha_emision',
        'disk',
        'pdf_path',
        'sha256_hash',
    ];

    protected $hidden = [
        'disk',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'calificacion_obtenida' => 'float',
            'fecha_emision' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agenteWorkspace(): BelongsTo
    {
        return $this->belongsTo(AgenteWorkspace::class, 'agente_workspace_id');
    }
}

