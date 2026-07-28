<?php

namespace App\Http\Controllers\Api;

use App\Models\CobranzaCuota;
use App\Models\Poliza;
use App\Services\WhatsApp\CobranzaReminderPlanner;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;

class CobranzaCuotaController extends BaseApiController
{
    public function __construct(
        private CobranzaReminderPlanner $whatsappReminderPlanner,
    ) {}
    public function index(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::COBRANZA_VIEW);
        $requestedWs = $request->filled('workspace_id') ? $request->integer('workspace_id') : null;
        $workspaceId = $this->resolveWorkspaceId($request, $requestedWs);

        $query = CobranzaCuota::query()
            ->with(['poliza.contratante', 'poliza.aseguradora', 'poliza.agenteWorkspace.agente'])
            ->where('workspace_id', $workspaceId)
            ->orderBy('fecha_programada')
            ->orderBy('numero_cuota');

        if ($request->filled('poliza_id')) {
            $query->where('poliza_id', $request->integer('poliza_id'));
        }

        if ($request->filled('estatus')) {
            $query->where('estatus', $request->string('estatus'));
        }

        if ($request->filled('desde')) {
            $query->whereDate('fecha_programada', '>=', $request->date('desde')->toDateString());
        }

        if ($request->filled('hasta')) {
            $query->whereDate('fecha_programada', '<=', $request->date('hasta')->toDateString());
        }

        return $query->paginate($request->integer('per_page', 30));
    }

    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::COBRANZA_MANAGE);
        $data = $request->validate([
            'workspace_id' => 'sometimes|exists:workspaces,id',
            'poliza_id' => 'required|exists:polizas,id',
            'numero_cuota' => 'nullable|integer|min:1',
            'fecha_programada' => 'required|date',
            'monto' => 'required|numeric|min:0',
            'estatus' => 'nullable|in:pendiente,pagado,vencido,cancelado',
            'telefono_notificacion' => 'nullable|string|max:30',
        ]);

        $workspaceId = $this->resolveWorkspaceId($request, $data['workspace_id'] ?? null);

        $poliza = Poliza::findOrFail($data['poliza_id']);
        if ((int) $poliza->workspace_id !== (int) $workspaceId) {
            return response()->json([
                'message' => 'La póliza no pertenece al workspace activo.',
            ], 422);
        }

        $numero = $data['numero_cuota'] ?? null;
        if ($numero === null) {
            $numero = (int) CobranzaCuota::query()
                ->where('poliza_id', $poliza->id)
                ->max('numero_cuota') + 1;
        }

        $cuota = CobranzaCuota::create([
            'workspace_id' => $workspaceId,
            'poliza_id' => $poliza->id,
            'numero_cuota' => $numero,
            'fecha_programada' => $data['fecha_programada'],
            'monto' => $data['monto'],
            'estatus' => $data['estatus'] ?? 'pendiente',
            'telefono_notificacion' => $data['telefono_notificacion'] ?? null,
        ]);

        if (($data['estatus'] ?? 'pendiente') === 'pendiente') {
            $this->whatsappReminderPlanner->planForCuota($cuota);
        }

        return response()->json($cuota->load(['poliza.contratante']), 201);
    }

    public function show(Request $request, CobranzaCuota $cobranzaCuota)
    {
        $this->requirePermission($request, WorkspacePermissions::COBRANZA_VIEW);
        $this->abortIfOutOfWorkspace($request, $cobranzaCuota->workspace_id);

        return $cobranzaCuota->load(['poliza.contratante', 'poliza.aseguradora']);
    }

    public function update(Request $request, CobranzaCuota $cobranzaCuota)
    {
        $this->requirePermission($request, WorkspacePermissions::COBRANZA_MANAGE);
        $this->abortIfOutOfWorkspace($request, $cobranzaCuota->workspace_id);

        $data = $request->validate([
            'fecha_programada' => 'sometimes|date',
            'monto' => 'sometimes|numeric|min:0',
            'estatus' => 'sometimes|in:pendiente,pagado,vencido,cancelado',
            'telefono_notificacion' => 'nullable|string|max:30',
        ]);

        $cobranzaCuota->update($data);

        if (isset($data['estatus']) && $data['estatus'] === 'pagado') {
            $this->whatsappReminderPlanner->cancelPendingForCuota($cobranzaCuota);
        } elseif (
            ($data['estatus'] ?? $cobranzaCuota->estatus) === 'pendiente'
            && (isset($data['fecha_programada']) || isset($data['telefono_notificacion']))
        ) {
            $this->whatsappReminderPlanner->cancelPendingForCuota($cobranzaCuota);
            $this->whatsappReminderPlanner->planForCuota($cobranzaCuota->fresh());
        }

        return $cobranzaCuota->fresh()->load(['poliza.contratante']);
    }

    public function destroy(Request $request, CobranzaCuota $cobranzaCuota)
    {
        $this->requirePermission($request, WorkspacePermissions::COBRANZA_MANAGE);
        $this->abortIfOutOfWorkspace($request, $cobranzaCuota->workspace_id);
        $cobranzaCuota->delete();

        return response()->noContent();
    }
}
