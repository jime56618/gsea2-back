<?php

namespace App\Http\Controllers\Api;

use App\Models\MetaComercial;
use App\Models\Poliza;
use App\Services\Authorization\WorkspaceAuthorizationService;
use App\Support\WorkspacePermissions as P;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;

class MetaComercialController extends BaseApiController
{
    private function isAdmin(Request $request, int $ws): bool
    {
        $role = app(WorkspaceAuthorizationService::class)->membership($request->user(), $ws)?->role;
        return $role && ($role->slug === 'admin' || in_array(strtolower($role->nombre), ['admin', 'administrador'], true));
    }

    public function index(Request $request)
    {
        $this->requirePermission($request, P::DASHBOARD_VIEW);
        $ws = $this->resolveWorkspaceId($request, $request->filled('workspace_id') ? $request->integer('workspace_id') : null);
        $data = $request->validate([
            'anio' => 'sometimes|integer|min:2000|max:2100',
            'mes' => 'sometimes|integer|min:1|max:12',
            'moneda' => 'sometimes|regex:/^[A-Z]{3}$/',
        ]);
        $today = Carbon::today('America/Merida');
        $anio = (int) ($data['anio'] ?? $today->year);
        $mes = (int) ($data['mes'] ?? $today->month);
        $available = Schema::hasTable('metas_comerciales');
        $auth = app(WorkspaceAuthorizationService::class);
        $admin = $this->isAdmin($request, $ws);
        $metas = $available ? MetaComercial::where('workspace_id', $ws)->where('anio', $anio)->where('mes', $mes)
            ->when(isset($data['moneda']), fn ($q) => $q->where('moneda', $data['moneda']))->orderBy('moneda')->get() : collect();
        $start = Carbon::create($anio, $mes, 1, 0, 0, 0, 'America/Merida');
        $actuals = $metas->isEmpty() ? collect() : Poliza::where('workspace_id', $ws)->whereIn('moneda', $metas->pluck('moneda'))
            ->whereDate('fecha_emision', '>=', $start->toDateString())
            ->whereDate('fecha_emision', '<', $start->copy()->addMonth()->toDateString())
            ->select('moneda')->selectRaw('COALESCE(SUM(prima_total), 0) as prima_emitida, COUNT(*) as polizas_emitidas')
            ->groupBy('moneda')->get()->keyBy('moneda');
        return response()->json([
            'workspace_id' => $ws, 'disponible' => $available, 'anio' => $anio, 'mes' => $mes,
            'puede_crear' => $available && $admin && $auth->hasPermission($request->user(), $ws, P::METAS_CREATE),
            'puede_editar' => $available && $admin && $auth->hasPermission($request->user(), $ws, P::METAS_UPDATE),
            'metas' => $metas->map(function (MetaComercial $meta) use ($actuals) {
                $actual = $actuals->get($meta->moneda);
                $real = (float) ($actual?->prima_emitida ?? 0);
                $target = (float) $meta->importe_objetivo;
                return [
                    'id' => $meta->id, 'anio' => $meta->anio, 'mes' => $meta->mes, 'moneda' => $meta->moneda,
                    'importe_objetivo' => $meta->importe_objetivo, 'prima_emitida' => round($real, 2),
                    'cumplimiento_pct' => $target > 0 ? round($real / $target * 100, 2) : null,
                    'polizas_emitidas' => (int) ($actual?->polizas_emitidas ?? 0),
                ];
            })->values(),
        ]);
    }

    private function authorizeWrite(Request $request, string $permission): int
    {
        $this->requirePermission($request, $permission);
        $ws = $this->resolveWorkspaceId($request, $request->filled('workspace_id') ? $request->integer('workspace_id') : null);
        abort_unless($this->isAdmin($request, $ws), 403, 'Solo administradores autorizados pueden configurar metas.');
        abort_unless(Schema::hasTable('metas_comerciales'), 503, 'La meta comercial requiere aplicar su migración.');
        return $ws;
    }

    public function store(Request $request)
    {
        $ws = $this->authorizeWrite($request, P::METAS_CREATE);
        $data = $request->validate([
            'anio' => 'required|integer|min:2000|max:2100',
            'mes' => 'required|integer|min:1|max:12',
            'moneda' => ['required', 'regex:/^[A-Z]{3}$/', Rule::unique('metas_comerciales')->where(fn ($q) => $q
                ->where('workspace_id', $ws)->where('anio', $request->integer('anio'))->where('mes', $request->integer('mes')))],
            'importe_objetivo' => 'required|numeric|decimal:0,2|min:0.01|max:99999999999999.99',
        ]);
        try {
            $meta = MetaComercial::create(['workspace_id' => $ws] + $data);
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['moneda' => 'Ya existe una meta para ese periodo y moneda.']);
        }
        return response()->json($meta, 201);
    }

    public function update(Request $request, int $id)
    {
        $ws = $this->authorizeWrite($request, P::METAS_UPDATE);
        $meta = MetaComercial::where('workspace_id', $ws)->findOrFail($id);
        // El periodo y la moneda identifican la meta y no se cambian al editar el importe.
        $data = $request->validate([
            'importe_objetivo' => 'required|numeric|decimal:0,2|min:0.01|max:99999999999999.99',
            'anio' => 'prohibited', 'mes' => 'prohibited', 'moneda' => 'prohibited',
        ]);
        $meta->update($data);
        return response()->json($meta);
    }
}
