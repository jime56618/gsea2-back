<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tramite extends Model
{
    protected $fillable = [
        'workspace_id',
        'folio',
        'tipo',
        'tipo_tramite',
        'etapa',
        'estado',
        'nombre_agente',
        'ramo',
        'subramo',
        'poliza_referencia',
        'fecha_alta',
        'fecha_ultima_modificacion',
        'observaciones',
        'aseguradora',
        'dias_fecha_alta',
        'dias_etapa_actual',
        'semaforo',
        'centro_emisor',
    ];

    protected function casts(): array
    {
        return [
            'fecha_alta' => 'date',
            'fecha_ultima_modificacion' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
