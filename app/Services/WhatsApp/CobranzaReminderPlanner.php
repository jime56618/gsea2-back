<?php

namespace App\Services\WhatsApp;

use App\Models\CobranzaCuota;
use App\Models\Poliza;
use App\Models\WhatsappNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CobranzaReminderPlanner
{
    public function __construct(
        private WhatsAppGateway $whatsapp,
        private WhatsappNotificationSender $sender,
    ) {}

    /**
     * Regenera recordatorios pendientes para todas las cuotas de la póliza.
     *
     * @param  bool  $isNewPoliza  Si true, puede enviar mensaje de alta inmediato.
     */
    public function syncForPoliza(Poliza $poliza, bool $isNewPoliza = false): void
    {
        if (! config('whatsapp.enabled')) {
            return;
        }

        $poliza->loadMissing(['contratante', 'cobranzaCuotas']);

        WhatsappNotification::query()
            ->where('poliza_id', $poliza->id)
            ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
            ->delete();

        $cuotas = $poliza->cobranzaCuotas()
            ->where('estatus', 'pendiente')
            ->orderBy('fecha_programada')
            ->orderBy('numero_cuota')
            ->get();

        if ($cuotas->isEmpty()) {
            return;
        }

        $offsets = config('whatsapp.reminder_offsets_days', [7, 3, 1, 0]);
        $today = Carbon::today(config('whatsapp.timezone'));

        DB::transaction(function () use ($poliza, $cuotas, $offsets, $today, $isNewPoliza) {
            foreach ($cuotas as $cuota) {
                $telefono = $this->resolvePhone($cuota, $poliza);
                if ($telefono === null) {
                    continue;
                }

                foreach ($offsets as $days) {
                    $days = (int) $days;
                    $programado = Carbon::parse($cuota->fecha_programada)
                        ->subDays($days)
                        ->startOfDay();

                    if ($programado->lt($today)) {
                        continue;
                    }

                    $tipo = WhatsappMessageBuilder::tipoForOffset($days);

                    WhatsappNotification::create([
                        'workspace_id' => $poliza->workspace_id,
                        'poliza_id' => $poliza->id,
                        'cobranza_cuota_id' => $cuota->id,
                        'tipo' => $tipo,
                        'telefono' => $telefono,
                        'mensaje' => WhatsappMessageBuilder::build($tipo, $poliza, $cuota),
                        'estatus' => WhatsappNotification::ESTATUS_PENDIENTE,
                        'programado_para' => $programado->toDateString(),
                    ]);
                }
            }

            if ($isNewPoliza && config('whatsapp.send_alta_on_poliza_sync', true)) {
                $this->scheduleAltaMessage($poliza, $cuotas->first());
            }
        });

        if ($isNewPoliza && config('whatsapp.send_alta_on_poliza_sync', true)) {
            $alta = WhatsappNotification::query()
                ->where('poliza_id', $poliza->id)
                ->where('tipo', WhatsappNotification::TIPO_ALTA)
                ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
                ->first();

            if ($alta && $this->whatsapp->isConfigured()) {
                $this->sender->send($alta);
            }
        }
    }

    public function cancelPendingForCuota(CobranzaCuota $cuota): void
    {
        WhatsappNotification::query()
            ->where('cobranza_cuota_id', $cuota->id)
            ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
            ->update(['estatus' => WhatsappNotification::ESTATUS_CANCELADO]);
    }

    public function planForCuota(CobranzaCuota $cuota): void
    {
        $cuota->loadMissing('poliza.contratante');
        $poliza = $cuota->poliza;

        if (! config('whatsapp.enabled') || $cuota->estatus !== 'pendiente') {
            return;
        }

        $telefono = $this->resolvePhone($cuota, $poliza);
        if ($telefono === null) {
            return;
        }

        $offsets = config('whatsapp.reminder_offsets_days', [7, 3, 1, 0]);
        $today = Carbon::today(config('whatsapp.timezone'));

        foreach ($offsets as $days) {
            $days = (int) $days;
            $programado = Carbon::parse($cuota->fecha_programada)
                ->subDays($days)
                ->startOfDay();

            if ($programado->lt($today)) {
                continue;
            }

            $tipo = WhatsappMessageBuilder::tipoForOffset($days);

            WhatsappNotification::updateOrCreate(
                [
                    'cobranza_cuota_id' => $cuota->id,
                    'tipo' => $tipo,
                ],
                [
                    'workspace_id' => $poliza->workspace_id,
                    'poliza_id' => $poliza->id,
                    'telefono' => $telefono,
                    'mensaje' => WhatsappMessageBuilder::build($tipo, $poliza, $cuota),
                    'estatus' => WhatsappNotification::ESTATUS_PENDIENTE,
                    'programado_para' => $programado->toDateString(),
                ]
            );
        }
    }

    private function scheduleAltaMessage(Poliza $poliza, ?CobranzaCuota $proximaCuota): void
    {
        if ($proximaCuota === null) {
            return;
        }

        $telefono = $this->resolvePhone($proximaCuota, $poliza);
        if ($telefono === null) {
            return;
        }

        $alreadySent = WhatsappNotification::query()
            ->where('poliza_id', $poliza->id)
            ->where('tipo', WhatsappNotification::TIPO_ALTA)
            ->where('estatus', WhatsappNotification::ESTATUS_ENVIADO)
            ->exists();

        if ($alreadySent) {
            return;
        }

        WhatsappNotification::create([
            'workspace_id' => $poliza->workspace_id,
            'poliza_id' => $poliza->id,
            'cobranza_cuota_id' => $proximaCuota->id,
            'tipo' => WhatsappNotification::TIPO_ALTA,
            'telefono' => $telefono,
            'mensaje' => WhatsappMessageBuilder::build(WhatsappNotification::TIPO_ALTA, $poliza, $proximaCuota),
            'estatus' => WhatsappNotification::ESTATUS_PENDIENTE,
            'programado_para' => Carbon::today(config('whatsapp.timezone'))->toDateString(),
        ]);
    }

    private function resolvePhone(CobranzaCuota $cuota, Poliza $poliza): ?string
    {
        $raw = $cuota->telefono_notificacion
            ?? $poliza->telefono_notificacion
            ?? $poliza->contratante?->telefono;

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }
}
