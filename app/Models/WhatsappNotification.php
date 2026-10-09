<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappNotification extends Model
{
    public const TIPO_ALTA = 'alta';

    public const TIPO_RECORDATORIO_7D = 'recordatorio_7d';

    public const TIPO_RECORDATORIO_3D = 'recordatorio_3d';

    public const TIPO_RECORDATORIO_1D = 'recordatorio_1d';

    public const TIPO_DIA_PAGO = 'dia_pago';

    /** Recordatorios por fin de vigencia de la póliza. */
    public const TIPO_VIGENCIA_30D = 'vigencia_30d';

    public const TIPO_VIGENCIA_15D = 'vigencia_15d';

    public const TIPO_VIGENCIA_7D = 'vigencia_7d';

    public const TIPO_VIGENCIA_1D = 'vigencia_1d';

    public const TIPO_VIGENCIA_HOY = 'vigencia_hoy';

    public const ESTATUS_PENDIENTE = 'pendiente';

    public const ESTATUS_ENVIADO = 'enviado';

    public const ESTATUS_FALLIDO = 'fallido';

    public const ESTATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'workspace_id',
        'poliza_id',
        'cobranza_cuota_id',
        'tipo',
        'telefono',
        'mensaje',
        'estatus',
        'twilio_sid',
        'programado_para',
        'enviado_en',
        'error_detalle',
    ];

    protected function casts(): array
    {
        return [
            'programado_para' => 'date',
            'enviado_en' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function poliza(): BelongsTo
    {
        return $this->belongsTo(Poliza::class);
    }

    public function cobranzaCuota(): BelongsTo
    {
        return $this->belongsTo(CobranzaCuota::class, 'cobranza_cuota_id');
    }
}
