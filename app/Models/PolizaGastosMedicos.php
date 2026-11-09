<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolizaGastosMedicos extends Model
{
    protected $table = 'poliza_gastos_medicos';
    protected $fillable = ['poliza_id', 'nombre_plan', 'nivel_hospitalario', 'suma_asegurada', 'suma_asegurada_texto', 'deducible', 'deducible_texto', 'coaseguro_porcentaje', 'tope_coaseguro', 'observaciones'];
    protected function casts(): array { return ['suma_asegurada' => 'decimal:2', 'deducible' => 'decimal:2', 'coaseguro_porcentaje' => 'decimal:2', 'tope_coaseguro' => 'decimal:2']; }
    public function poliza(): BelongsTo { return $this->belongsTo(Poliza::class); }
}
