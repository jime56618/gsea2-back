<?php

namespace App\Services\Exports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Consultas independientes de lectura. Nunca invoca servicios operativos ni serializa modelos completos. */
class ExportDataset
{
    public const MODULES = ['tramites', 'clientes', 'agentes', 'polizas', 'cobranza'];

    public function query(string $module, int $ws): Builder
    {
        if ($module === 'tramites') return DB::table('tramites as r')->where('r.workspace_id', $ws)->select('r.*');
        if ($module === 'clientes') return DB::table('contratantes as r')->where('r.workspace_id', $ws)->select('r.*')
            ->selectSub(DB::table('polizas as cp')->selectRaw('COUNT(*)')->whereColumn('cp.contratante_id', 'r.id')->where('cp.workspace_id', $ws), 'polizas_count');
        if ($module === 'agentes') return DB::table('agente_workspaces as r')->where('r.workspace_id', $ws)
            ->join('agentes as a', 'a.id', '=', 'r.agente_id')->select('r.id', 'r.status', 'a.nombre', 'a.apellido', 'a.cedula',
                'a.email', 'a.telefono', 'a.ciudad', 'a.estado', 'a.curp', 'a.rfc', 'a.direccion', 'a.fecha_nacimiento', 'a.fecha_alta');
        if ($module === 'polizas') {
            return DB::table('polizas as r')->where('r.workspace_id', $ws)
                ->leftJoin('contratantes as c', fn ($j) => $j->on('c.id', '=', 'r.contratante_id')->where('c.workspace_id', $ws))
                ->leftJoin('aseguradoras as s', 's.id', '=', 'r.aseguradora_id')
                ->select('r.*', 'c.nombre as cliente', 's.nombre as aseguradora');
        }
        return DB::table('cobranza_cuotas as r')->where('r.workspace_id', $ws)
            ->join('polizas as p', fn ($j) => $j->on('p.id', '=', 'r.poliza_id')->where('p.workspace_id', $ws))
            ->leftJoin('contratantes as c', fn ($j) => $j->on('c.id', '=', 'p.contratante_id')->where('c.workspace_id', $ws))
            ->leftJoin('agente_workspaces as aw', fn ($j) => $j->on('aw.id', '=', 'p.agente_id')->where('aw.workspace_id', $ws))
            ->leftJoin('agentes as a', 'a.id', '=', 'aw.agente_id')
            ->select('r.*', 'p.numero_poliza as poliza', 'p.moneda', 'c.nombre as cliente', 'a.nombre as agente_nombre', 'a.apellido as agente_apellido');
    }

    /** Memoria acotada: bloques de 500, paginación por PK y límite superior capturado al comenzar. */
    public function rows(string $module, int $ws, array $ids = []): \Generator
    {
        $base = $this->query($module, $ws);
        if ($ids) $base->whereIn('r.id', $ids);
        $max = (int) (clone $base)->max('r.id');
        $after = 0;
        while (true) {
            $batch = (clone $base)->where('r.id', '>', $after)->where('r.id', '<=', $max)->orderBy('r.id')->limit(500)->get();
            if ($batch->isEmpty()) break;
            $extras = [];
            if ($module === 'agentes') {
                $extras = DB::table('agente_workspace_aseguradora as k')->join('aseguradoras as s', 's.id', '=', 'k.aseguradora_id')
                    ->whereIn('k.agente_workspace_id', $batch->pluck('id'))->select('k.agente_workspace_id', 's.nombre')->get()
                    ->groupBy('agente_workspace_id')->map(fn ($items) => $items->pluck('nombre')->all())->all();
            }
            foreach ($batch as $item) {
                $row = (array) $item;
                if ($module === 'tramites') $row['folio'] = $row['folio'] ?: (string) $row['id'];
                if ($module === 'agentes') {
                    $row['nombre_completo'] = trim(($row['nombre'] ?? '').' '.($row['apellido'] ?? ''));
                    $row['aseguradoras_lista'] = $extras[$row['id']] ?? [];
                    $row['aseguradoras'] = implode(', ', $row['aseguradoras_lista']);
                    $row['status'] = $row['status'] ?: 'activo';
                }
                if ($module === 'cobranza') {
                    $row['agente'] = trim(($row['agente_nombre'] ?? '').' '.($row['agente_apellido'] ?? '')) ?: '—';
                    $row['cliente'] = $row['cliente'] ?: '—'; $row['poliza'] = $row['poliza'] ?: '—';
                }
                if ($module === 'polizas') {
                    $row['cliente'] = $row['cliente'] ?: '—'; $row['aseguradora'] = $row['aseguradora'] ?: '—';
                }
                yield $row;
            }
            $after = (int) $batch->last()->id;
        }
    }

    public function matches(string $module, array $r, array $f): bool
    {
        $q = mb_strtolower($f['q'] ?? '');
        $fields = match ($module) {
            'tramites' => [(string) ($r['folio'] ?: $r['id']), $r['nombre_agente'] ?: '—', $r['ramo'] ?: '—', $r['etapa'] ?: '—'],
            'clientes' => [$r['nombre'], $r['rfc'], $r['email']],
            'agentes' => array_map(fn ($key) => in_array($key, ['fecha_nacimiento','fecha_alta'], true) && !empty($r[$key])
                ? substr($r[$key], 0, 10).'T00:00:00.000000Z' : ($r[$key] ?? ''), ['nombre_completo','cedula','telefono','ciudad','estado','status','fecha_nacimiento','curp','rfc','direccion','fecha_alta']),
            'polizas' => [$r['numero_poliza'], $r['cliente'], $r['aseguradora']],
            'cobranza' => [$r['cliente'], $r['poliza'], $r['agente']],
        };
        $search = $q === '' || collect($fields)->contains(fn ($s) => str_contains(mb_strtolower((string) $s), $q));
        if ($module === 'clientes') $search = $search || str_contains((string) ($r['telefono'] ?? ''), $q);
        if (! $search) return false;
        if ($module === 'tramites') {
            $color = mb_strtolower($r['semaforo'] ?: 'verde');
            if (! in_array($color, ['verde','amarillo','rojo'], true)) $color = 'verde'; // Mismo filtro visual del listado; se exporta el valor original.
            return (($f['semaforo'] ?? 'todos') === 'todos' || $color === $f['semaforo'])
                && (! isset($f['estado']) || ($r['estado'] ?? null) === $f['estado']);
        }
        if ($module === 'agentes') return (($f['status'] ?? 'todos') === 'todos' || $r['status'] === $f['status'])
            && (($f['empresa'] ?? 'todos') === 'todos' || in_array($f['empresa'], $r['aseguradoras_lista'], true));
        if ($module === 'cobranza') return (($f['estatus'] ?? 'todos') === 'todos' || ($r['estatus'] ?: 'pendiente') === $f['estatus'])
            && (! isset($f['poliza_id']) || (int) $r['poliza_id'] === (int) $f['poliza_id'])
            && (! isset($f['desde']) || substr($r['fecha_programada'], 0, 10) >= $f['desde'])
            && (! isset($f['hasta']) || substr($r['fecha_programada'], 0, 10) <= $f['hasta']);
        if ($module === 'polizas') {
            $today = $f['hoy'] ?? Carbon::today('America/Merida')->toDateString();
            if (($f['vencidas'] ?? false)) return $r['fin_vigencia'] && substr($r['fin_vigencia'],0,10) < $today;
            $status = 'No Vigente';
            if ($r['inicio_vigencia'] && $r['fin_vigencia']) {
                // Reproduce las fechas locales usadas por computeEstatus en el navegador, sin modificar el listado.
                $offset = $f['tz_offset'] ?? 0;
                $start = Carbon::parse($r['inicio_vigencia'], 'UTC')->subMinutes($offset)->toDateString();
                $end = Carbon::parse($r['fin_vigencia'], 'UTC')->subMinutes($offset)->toDateString();
                if ($start <= $today && $today <= $end) $status = 'Vigente';
            }
            return ($f['estatus'] ?? 'Todos') === 'Todos' || $status === $f['estatus'];
        }
        return true;
    }

    /** Lista cerrada: etiqueta => [campo, tipo]. Ninguna credencial ni relación completa se exporta. */
    public function columns(string $module): array
    {
        $text = fn ($key) => [$key, 'text']; $date = fn ($key) => [$key, 'date']; $number = fn ($key) => [$key, 'number'];
        return match ($module) {
            'tramites' => ['Folio'=>$text('folio'),'Etapa'=>$text('etapa'),'Estado de atención'=>$text('estado'),'Semáforo'=>$text('semaforo'),
                'Agente'=>$text('nombre_agente'),'Ramo'=>$text('ramo'),'Póliza de referencia'=>$text('poliza_referencia'),
                'Fecha de alta'=>$date('fecha_alta'),'Última modificación'=>$date('fecha_ultima_modificacion')],
            'clientes' => ['Nombre'=>$text('nombre'),'RFC'=>$text('rfc'),'Correo electrónico'=>$text('email'),'Teléfono'=>$text('telefono'),
                'Dirección'=>$text('direccion'),'Pólizas'=>$number('polizas_count'),'Fecha de alta'=>$date('created_at')],
            'agentes' => ['Agente'=>$text('nombre_completo'),'Cédula'=>$text('cedula'),'Correo electrónico'=>$text('email'),
                'Teléfono'=>$text('telefono'),'Ciudad'=>$text('ciudad'),'Estado'=>$text('estado'),'Situación'=>$text('status'),
                'Fecha de alta'=>$date('fecha_alta'),'Aseguradoras'=>$text('aseguradoras')],
            'polizas' => ['Número de póliza'=>$text('numero_poliza'),'Cliente'=>$text('cliente'),'Aseguradora'=>$text('aseguradora'),
                'Fecha de emisión'=>$date('fecha_emision'),'Inicio de vigencia'=>$date('inicio_vigencia'),'Fin de vigencia'=>$date('fin_vigencia'),
                'Prima neta'=>$number('prima_neta'),'IVA'=>$number('iva'),'Prima total'=>$number('prima_total'),'Moneda'=>$text('moneda')],
            'cobranza' => ['Póliza'=>$text('poliza'),'Cliente'=>$text('cliente'),'Agente'=>$text('agente'),'Número de cuota'=>$number('numero_cuota'),
                'Fecha programada'=>$date('fecha_programada'),'Monto'=>$number('monto'),'Moneda'=>$text('moneda'),'Estatus'=>$text('estatus')],
        };
    }
}
