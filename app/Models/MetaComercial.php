<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaComercial extends Model
{
    protected $table = 'metas_comerciales';

    protected $fillable = ['workspace_id', 'anio', 'mes', 'moneda', 'importe_objetivo'];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'mes' => 'integer', 'importe_objetivo' => 'decimal:2'];
    }
}
