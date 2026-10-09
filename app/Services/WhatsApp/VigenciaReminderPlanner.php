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

        $tz = config('whatsapp.timezone', 'America/Mexico_City');
        // Comparar solo la fecha (evita que un cast UTC “se coma” el día 0).
        $fin = Carbon::createFromFormat('Y-m-d', Carbon::parse($poliza->fin_vigencia)->toDateString(), $tz)->startOfDay();
        $today = Carbon::today($tz);

        if ($fin->lt($today)) {
            return;
        }

        $offsets = config('whatsapp.vigencia_offsets_days', [30, 15, 7, 1, 0]);

        foreach ($offsets as $days) {
            $days = (int) $days;
            $programado = $fin->copy()->subDays($days)->startOfDay();

            // Incluye el día exacto (offset 0 = hoy vence).
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
            $poliza->loadMissing('cobranzaCuotas');
            $raw = $poliza->cobranzaCuotas
                ->first(fn ($c) => filled($c->telefono_notificacion))
                ?->telefono_notificacion;
        }

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return trim((string) $raw);
    }
}
