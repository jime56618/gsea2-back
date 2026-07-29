<?php

namespace App\Services;

/**
 * Calcula IVA, prima total y monto por cuota a partir de prima neta y frecuencia.
 */
class PolizaPremiumCalculator
{
    public const IVA_RATE = 0.16;

    /**
     * Completa montos de cobro.
     *
     * - Con prima_neta: IVA = 16% y prima_total = neta + IVA (siempre).
     * - monto_cuota vacío: se divide prima_total según frecuencia.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function apply(array $data, ?float $fallbackPrimaNeta = null, ?float $fallbackPrimaTotal = null, ?string $fallbackFrecuencia = null): array
    {
        $frecuencia = $data['frecuencia_cobro'] ?? $fallbackFrecuencia ?? 'unico';

        $primaNeta = $this->toFloatOrNull($data['prima_neta'] ?? null);
        if ($primaNeta === null) {
            $primaNeta = $fallbackPrimaNeta;
        }

        $cuotaExplicit = array_key_exists('monto_cuota', $data)
            && $data['monto_cuota'] !== null
            && $data['monto_cuota'] !== '';

        $primaTotal = $this->toFloatOrNull($data['prima_total'] ?? null) ?? $fallbackPrimaTotal;

        if ($primaNeta !== null) {
            $iva = round($primaNeta * self::IVA_RATE, 2);
            $primaTotal = round($primaNeta + $iva, 2);
            $data['prima_neta'] = $primaNeta;
            $data['iva'] = $iva;
            $data['prima_total'] = $primaTotal;
        } elseif ($primaTotal !== null) {
            $data['prima_total'] = $primaTotal;
        }

        if (! $cuotaExplicit && $primaTotal !== null) {
            $data['monto_cuota'] = $this->montoPorCuota($primaTotal, (string) $frecuencia);
        } elseif ($cuotaExplicit) {
            $data['monto_cuota'] = $this->toFloatOrNull($data['monto_cuota']);
        }

        return $data;
    }

    public function montoPorCuota(float $primaTotal, string $frecuencia): float
    {
        $divisor = match ($frecuencia) {
            'mensual' => 12,
            'trimestral' => 4,
            'semestral' => 2,
            'anual', 'unico' => 1,
            default => 1,
        };

        return round($primaTotal / $divisor, 2);
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
