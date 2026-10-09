<?php

namespace App\Services\WhatsApp;

use App\Models\CobranzaCuota;
use App\Models\Poliza;
use App\Models\WhatsappNotification;
use Carbon\Carbon;

class WhatsappMessageBuilder
{
    public static function build(string $tipo, Poliza $poliza, ?CobranzaCuota $cuota = null): string
    {
        $poliza->loadMissing(['contratante', 'aseguradora']);
        $nombre = self::nombreCliente($poliza);
        $numero = $poliza->numero_poliza;
        $frecuencia = self::labelFrecuencia($poliza->frecuencia_cobro ?? 'unico');

        if ($tipo === WhatsappNotification::TIPO_ALTA) {
            $poliza->loadMissing('workspace');
            $empresa = $poliza->workspace?->nombre ?? 'G-SEA';
            $proxima = $cuota
                ? Carbon::parse($cuota->fecha_programada)->format('d/m/Y')
                : '—';
            $monto = $cuota ? self::formatMoney($cuota->monto, $poliza->moneda) : '—';

            return "¡Hola, {$nombre}!\n"
                ."Este es tu primer recordatorio de tu póliza {$numero} con {$empresa}.\n"
                ."Recuerda que tu próximo pago es el {$proxima} por {$monto} ({$frecuencia}).\n"
                .'Te enviaremos avisos antes de cada fecha de pago.';
        }

        if (str_starts_with($tipo, 'vigencia_')) {
            $days = self::offsetFromVigenciaTipo($tipo);

            return self::buildVigencia($tipo, $poliza, $days);
        }

        if ($cuota === null) {
            return "¡Hola, {$nombre}! Recordatorio de tu póliza {$numero}.";
        }

        $fecha = Carbon::parse($cuota->fecha_programada)->format('d/m/Y');
        $monto = self::formatMoney($cuota->monto, $poliza->moneda);

        return match ($tipo) {
            WhatsappNotification::TIPO_RECORDATORIO_7D => "¡Hola, {$nombre}!\n"
                ."Te recordamos que tu póliza {$numero} tiene un pago programado para el {$fecha} por {$monto}.\n"
                .'Si ya realizaste el pago, puedes ignorar este mensaje.',

            WhatsappNotification::TIPO_RECORDATORIO_3D => "¡Hola, {$nombre}!\n"
                ."Tu póliza {$numero} tiene fecha de pago el {$fecha} (en 3 días).\n"
                ."Monto: {$monto}.",

            WhatsappNotification::TIPO_RECORDATORIO_1D => "¡Hola, {$nombre}!\n"
                ."Mañana vence el pago de tu póliza {$numero}.\n"
                ."Fecha: {$fecha} · Monto: {$monto}.",

            WhatsappNotification::TIPO_DIA_PAGO => "¡Hola, {$nombre}!\n"
                ."Hoy es la fecha de pago de tu póliza {$numero}.\n"
                ."Monto: {$monto}.\n"
                .'Si ya pagaste, ignora este mensaje.',

            default => "¡Hola, {$nombre}!\n"
                ."Recordatorio: póliza {$numero}, pago {$fecha}, {$monto}.",
        };
    }

    /**
     * Texto / cuerpo plantilla g_pruebas2 (vencimiento).
     */
    public static function buildVigencia(string $tipo, Poliza $poliza, int $daysBefore): string
    {
        $poliza->loadMissing(['contratante', 'aseguradora', 'workspace']);
        $nombre = self::nombreCliente($poliza);
        $numero = $poliza->numero_poliza ?? '—';
        $aseguradora = $poliza->aseguradora?->nombre ?? ($poliza->workspace?->nombre ?? 'G-SEA');
        $fecha = $poliza->fin_vigencia
            ? Carbon::parse($poliza->fin_vigencia)->format('d/m/Y')
            : '—';
        $diasTexto = self::textoDiasRestantes($daysBefore);

        return "¡Hola, {$nombre}!\n"
            ."Tu póliza {$numero} con {$aseguradora} vence el {$fecha}.\n"
            ."Te recomendamos renovarla a tiempo.\n"
            ."{$diasTexto}\n"
            .'Contacta a tu asesor para más información.';
    }

    public static function textoDiasRestantes(int $daysBefore): string
    {
        if ($daysBefore <= 0) {
            return 'Hoy vence tu póliza.';
        }

        if ($daysBefore === 1) {
            return 'Falta 1 día para el vencimiento.';
        }

        return "Faltan {$daysBefore} días para el vencimiento.";
    }

    private static function nombreCliente(Poliza $poliza): string
    {
        $c = $poliza->contratante;

        if (! $c) {
            return 'cliente';
        }

        $nombre = trim($c->nombre ?? '');

        return $nombre !== '' ? $nombre : 'cliente';
    }

    private static function formatMoney(mixed $amount, ?string $moneda): string
    {
        $m = $moneda ?? 'MXN';

        return '$'.number_format((float) $amount, 2).' '.$m;
    }

    private static function labelFrecuencia(string $freq): string
    {
        return match ($freq) {
            'mensual' => 'mensual',
            'trimestral' => 'trimestral',
            'semestral' => 'semestral',
            'anual' => 'anual',
            default => 'pago único',
        };
    }

    public static function tipoForOffset(int $days): string
    {
        return match ($days) {
            7 => WhatsappNotification::TIPO_RECORDATORIO_7D,
            3 => WhatsappNotification::TIPO_RECORDATORIO_3D,
            1 => WhatsappNotification::TIPO_RECORDATORIO_1D,
            0 => WhatsappNotification::TIPO_DIA_PAGO,
            default => 'recordatorio_'.$days.'d',
        };
    }

    public static function tipoVigenciaForOffset(int $days): string
    {
        return match ($days) {
            30 => WhatsappNotification::TIPO_VIGENCIA_30D,
            15 => WhatsappNotification::TIPO_VIGENCIA_15D,
            7 => WhatsappNotification::TIPO_VIGENCIA_7D,
            1 => WhatsappNotification::TIPO_VIGENCIA_1D,
            0 => WhatsappNotification::TIPO_VIGENCIA_HOY,
            default => 'vigencia_'.$days.'d',
        };
    }

    public static function offsetFromVigenciaTipo(string $tipo): int
    {
        return match ($tipo) {
            WhatsappNotification::TIPO_VIGENCIA_30D => 30,
            WhatsappNotification::TIPO_VIGENCIA_15D => 15,
            WhatsappNotification::TIPO_VIGENCIA_7D => 7,
            WhatsappNotification::TIPO_VIGENCIA_1D => 1,
            WhatsappNotification::TIPO_VIGENCIA_HOY => 0,
            default => (int) preg_replace('/\D+/', '', $tipo),
        };
    }
}
