<?php

namespace App\Services\WhatsApp;

use App\Models\Poliza;
use App\Models\WhatsappNotification;
use Carbon\Carbon;

/**
 * Programa recordatorios WhatsApp por fin de vigencia de la póliza.
 */
class VigenciaReminderPlanner
{
    /**
     * Regenera avisos de vencimiento pendientes (no toca cobranza ni alta).
     */
    public function syncForPoliza(Poliza $poliza): void
    {
        if (! config('whatsapp.enabled')) {
            return;
        }

        $poliza->loadMissing(['contratante', 'aseguradora', 'workspace']);

        WhatsappNotification::query()
            ->where('poliza_id', $poliza->id)
            ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
            ->where('tipo', 'like', 'vigencia_%')
            ->delete();

        if ($poliza->fin_vigencia === null) {
            return;
        }

        $telefono = $this->resolvePhone($poliza);
        if ($telefono === null) {
            return;
        }

        $fin = Carbon::parse($poliza->fin_vigencia)->startOfDay();
        $today = Carbon::today(config('whatsapp.timezone'));

        if ($fin->lt($today)) {
            return;
        }

        $offsets = config('whatsapp.vigencia_offsets_days', [30, 15, 7, 1, 0]);

        foreach ($offsets as $days) {
            $days = (int) $days;
            $programado = $fin->copy()->subDays($days)->startOfDay();

            if ($programado->lt($today)) {
                continue;
            }

            $tipo = WhatsappMessageBuilder::tipoVigenciaForOffset($days);

            WhatsappNotification::create([
                'workspace_id' => $poliza->workspace_id,
                'poliza_id' => $poliza->id,
                'cobranza_cuota_id' => null,
                'tipo' => $tipo,
                'telefono' => $telefono,
                'mensaje' => WhatsappMessageBuilder::buildVigencia($tipo, $poliza, $days),
                'estatus' => WhatsappNotification::ESTATUS_PENDIENTE,
                'programado_para' => $programado->toDateString(),
            ]);
        }
    }

    private function resolvePhone(Poliza $poliza): ?string
    {
        $raw = $poliza->telefono_notificacion
            ?? $poliza->contratante?->telefono;

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }
}
