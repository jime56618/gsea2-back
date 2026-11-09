<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolizaBeneficiario extends Model
{
    protected $table = 'poliza_beneficiarios';
    protected $fillable = ['poliza_id', 'asegurado_id', 'nombre', 'parentesco', 'porcentaje', 'fecha_nacimiento'];
    protected function casts(): array { return ['porcentaje' => 'decimal:2', 'fecha_nacimiento' => 'date']; }
    public function poliza(): BelongsTo { return $this->belongsTo(Poliza::class); }
}
