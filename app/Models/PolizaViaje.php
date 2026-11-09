<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolizaViaje extends Model
{
    protected $table = 'poliza_viajes';
    protected $fillable = ['poliza_id', 'nombre_plan', 'origen', 'destino', 'fecha_inicio_viaje', 'fecha_fin_viaje', 'observaciones'];
    protected function casts(): array { return ['fecha_inicio_viaje' => 'date', 'fecha_fin_viaje' => 'date']; }
    public function poliza(): BelongsTo { return $this->belongsTo(Poliza::class); }
}
