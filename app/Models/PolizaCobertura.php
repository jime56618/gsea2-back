<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolizaCobertura extends Model
{
    protected $table = 'poliza_coberturas';
    protected $fillable = ['poliza_id', 'nombre', 'clave', 'suma_asegurada', 'suma_asegurada_texto', 'deducible', 'deducible_texto', 'coaseguro_porcentaje', 'prima', 'moneda', 'observaciones', 'orden'];
    protected function casts(): array { return ['suma_asegurada' => 'decimal:2', 'deducible' => 'decimal:2', 'coaseguro_porcentaje' => 'decimal:2', 'prima' => 'decimal:2', 'orden' => 'integer']; }
    public function poliza(): BelongsTo { return $this->belongsTo(Poliza::class); }
}
