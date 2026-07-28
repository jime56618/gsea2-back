<?php

namespace App\Services;

use App\Models\CobranzaCuota;
use App\Models\Poliza;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CobranzaCuotasGenerator
{
    /**
     * Regenera todas las cuotas de la póliza (borra anteriores). Úsalo al crear/actualizar condiciones de cobro.
     */
    public function syncForPoliza(Poliza $poliza): void
    {
        CobranzaCuota::query()->where('poliza_id', $poliza->id)->delete();

        $freq = $poliza->frecuencia_cobro ?? 'unico';
        $inicio = Carbon::parse($poliza->inicio_vigencia)->startOfDay();
        $fin = Carbon::parse($poliza->fin_vigencia)->endOfDay();

        $monto = $poliza->monto_cuota;
        if ($monto === null && $poliza->prima_total !== null) {
            $monto = $poliza->prima_total;
        }
        if ($monto === null) {
            return;
        }

        $telefono = $poliza->telefono_notificacion;
        if ($telefono === null && $poliza->relationLoaded('contratante')) {
            $telefono = $poliza->contratante?->telefono;
        } elseif ($telefono === null) {
            $poliza->load('contratante');
            $telefono = $poliza->contratante?->telefono;
        }

        $fechas = match ($freq) {
            'mensual' => $this->fechasRecurrentes($inicio, $fin, fn (Carbon $d) => $d->copy()->addMonth()),
            'trimestral' => $this->fechasRecurrentes($inicio, $fin, fn (Carbon $d) => $d->copy()->addMonths(3)),
            'semestral' => $this->fechasRecurrentes($inicio, $fin, fn (Carbon $d) => $d->copy()->addMonths(6)),
            'anual' => $this->fechasRecurrentes($inicio, $fin, fn (Carbon $d) => $d->copy()->addYear()),
            default => collect([$inicio->copy()]),
        };

        $n = 1;
        $rows = [];
        foreach ($fechas as $fecha) {
            $rows[] = [
                'workspace_id' => $poliza->workspace_id,
                'poliza_id' => $poliza->id,
                'numero_cuota' => $n,
                'fecha_programada' => $fecha->toDateString(),
                'monto' => $monto,
                'estatus' => 'pendiente',
                'telefono_notificacion' => $telefono,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $n++;
        }

        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('cobranza_cuotas')->insert($chunk);
        }
    }

    /**
     * @param  callable(Carbon): Carbon  $advance
     * @return \Illuminate\Support\Collection<int, Carbon>
     */
    private function fechasRecurrentes(Carbon $inicio, Carbon $fin, callable $advance): \Illuminate\Support\Collection
    {
        $out = collect();
        $d = $inicio->copy();
        while ($d->lte($fin)) {
            $out->push($d->copy());
            $next = $advance($d);
            if ($next->equalTo($d)) {
                break;
            }
            $d = $next;
        }

        return $out;
    }
}
