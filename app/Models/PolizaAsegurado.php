<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolizaAsegurado extends Model
{
    protected $table = 'poliza_asegurados';
    protected $fillable = ['poliza_id', 'nombre', 'fecha_nacimiento', 'sexo', 'parentesco', 'rfc', 'curp', 'antiguedad', 'titular'];
    protected function casts(): array { return ['fecha_nacimiento' => 'date', 'antiguedad' => 'date', 'titular' => 'boolean']; }
    public function poliza(): BelongsTo { return $this->belongsTo(Poliza::class); }
}
