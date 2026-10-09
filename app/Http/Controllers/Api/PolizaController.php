<?php

namespace App\Http\Controllers\Api;

use App\Models\AgenteWorkspace;
use App\Models\Contratante;
use App\Models\Poliza;
use App\Models\PolizaVehiculo;
use App\Models\Subramo;
use App\Services\CobranzaCuotasGenerator;
use App\Services\PolizaPremiumCalculator;
use App\Services\WhatsApp\CobranzaReminderPlanner;
use App\Services\WhatsApp\VigenciaReminderPlanner;
use App\Services\WhatsApp\WhatsappNotificationSender;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolizaController extends BaseApiController
{
    public function __construct(
        private CobranzaCuotasGenerator $cobranzaCuotasGenerator,
        private CobranzaReminderPlanner $whatsappReminderPlanner,
        private VigenciaReminderPlanner $vigenciaReminderPlanner,
        private WhatsappNotificationSender $whatsappSender,
        private PolizaPremiumCalculator $premiumCalculator,
    ) {}

    public function index(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::POLIZAS_VIEW);
        $requestedWs = $request->filled('workspace_id') ? $request->integer('workspace_id') : null;
        $workspaceId = $this->resolveWorkspaceId($request, $requestedWs);

        $query = Poliza::query()
            ->with([
                'contratante',
                'aseguradora',
                'ramo',
                'subramo',
                'agenteWorkspace',
                'vehiculo',
            ])
            ->withCount('cobranzaCuotas')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('inicio_vigencia');

        if ($request->filled('contratante_id')) {
            $query->where('contratante_id', $request->integer('contratante_id'));
        }

        return $query->paginate($request->integer('per_page', 15));
    }

    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::POLIZAS_MANAGE);
        $data = $request->validate([
            'workspace_id' => 'sometimes|exists:workspaces,id',
            'contratante_id' => 'required|exists:contratantes,id',
            'agente_id' => 'nullable|exists:agente_workspaces,id',
            'aseguradora_id' => 'required|exists:aseguradoras,id',
            'ramo_id' => 'required|exists:ramos,id',
            'subramo_id' => 'required|exists:subramos,id',
            'numero_poliza' => 'required|string|max:255',
            'fecha_emision' => 'nullable|date',
            'inicio_vigencia' => 'required|date',
            'fin_vigencia' => 'required|date|after_or_equal:inicio_vigencia',
            'prima_neta' => 'nullable|numeric|min:0',
            'iva' => 'nullable|numeric|min:0',
            'prima_total' => 'nullable|numeric|min:0',
            'moneda' => 'nullable|string|max:10',
            'frecuencia_cobro' => 'nullable|in:unico,mensual,trimestral,semestral,anual',
            'monto_cuota' => 'nullable|numeric|min:0',
            'telefono_notificacion' => 'nullable|string|max:30',
            'vehiculo' => 'nullable|array',
            'vehiculo.marca' => 'nullable|string|max:255',
            'vehiculo.modelo' => 'nullable|string|max:255',
            'vehiculo.anio' => 'nullable|integer|min:1900|max:2100',
            'vehiculo.serie' => 'nullable|string|max:255',
            'vehiculo.placas' => 'nullable|string|max:50',
            'vehiculo.motor' => 'nullable|string|max:255',
            'vehiculo.pasajeros' => 'nullable|integer|min:0',
        ]);
        $reqWs = $data['workspace_id'] ?? null;
        if ($reqWs === 0 || $reqWs === '0') {
            $reqWs = null;
        }
        $workspaceId = $this->resolveWorkspaceId($request, $reqWs !== null ? (int) $reqWs : null);

        $contratante = Contratante::findOrFail($data['contratante_id']);
        if ((int) $contratante->workspace_id !== (int) $workspaceId) {
            return response()->json([
                'message' => 'El contratante no pertenece al workspace indicado.',
            ], 422);
        }

        if (! empty($data['agente_id'])) {
            $agenteWorkspace = AgenteWorkspace::query()
                ->with('clavesAseguradora')
                ->findOrFail($data['agente_id']);
            if ((int) $agenteWorkspace->workspace_id !== (int) $workspaceId) {
                return response()->json([
                    'message' => 'El agente no pertenece al workspace indicado.',
                ], 422);
            }

            $hasClave = $agenteWorkspace->clavesAseguradora
                ->contains('aseguradora_id', (int) $data['aseguradora_id']);

            if (! $hasClave) {
                return response()->json([
                    'message' => 'El agente no tiene clave para la aseguradora seleccionada.',
                ], 422);
            }
        }

        $subramo = Subramo::findOrFail($data['subramo_id']);
        if ((int) $subramo->ramo_id !== (int) $data['ramo_id']) {
            return response()->json([
                'message' => 'El subramo no corresponde al ramo indicado.',
            ], 422);
        }

        $data = $this->premiumCalculator->apply($data);

        return DB::transaction(function () use ($data, $request, $workspaceId) {
            $poliza = Poliza::create([
                'workspace_id' => $workspaceId,
                'contratante_id' => $data['contratante_id'],
                'agente_id' => $data['agente_id'] ?? null,
                'aseguradora_id' => $data['aseguradora_id'],
                'ramo_id' => $data['ramo_id'],
                'subramo_id' => $data['subramo_id'],
                'numero_poliza' => $data['numero_poliza'],
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'inicio_vigencia' => $data['inicio_vigencia'],
                'fin_vigencia' => $data['fin_vigencia'],
                'prima_neta' => $data['prima_neta'] ?? null,
                'iva' => $data['iva'] ?? null,
                'prima_total' => $data['prima_total'] ?? null,
                'moneda' => $data['moneda'] ?? 'MXN',
                'frecuencia_cobro' => $data['frecuencia_cobro'] ?? 'unico',
                'monto_cuota' => $data['monto_cuota'] ?? null,
                'telefono_notificacion' => $data['telefono_notificacion'] ?? null,
            ]);

            if ($request->filled('vehiculo') && is_array($request->input('vehiculo'))) {
                $vehAttrs = array_filter(
                    $request->input('vehiculo', []),
                    fn ($v) => $v !== null && $v !== ''
                );
                if ($vehAttrs !== []) {
                    PolizaVehiculo::create(array_merge(['poliza_id' => $poliza->id], $vehAttrs));
                }
            }

            $poliza->load('contratante');
            $this->cobranzaCuotasGenerator->syncForPoliza($poliza);
            $fresh = $poliza->fresh();
            $this->whatsappReminderPlanner->syncForPoliza($fresh, isNewPoliza: true);
            $this->vigenciaReminderPlanner->syncForPoliza($fresh);
            // Si hoy hay pago y/o vence hoy: manda ambos al momento (además del alta).
            $this->whatsappSender->sendDueForPoliza((int) $fresh->id, includeAlta: false);

            return response()->json(
                $poliza->fresh()->load([
                    'contratante',
                    'aseguradora',
                    'ramo',
                    'subramo',
                    'agenteWorkspace',
                    'vehiculo',
                    'cobranzaCuotas',
                ]),
                201
            );
        });
    }

    public function show(Poliza $poliza)
    {
        $this->requirePermission(request(), WorkspacePermissions::POLIZAS_VIEW);
        $this->abortIfOutOfWorkspace(request(), $poliza->workspace_id);

        return $poliza->load([
            'workspace',
            'contratante',
            'aseguradora',
            'ramo',
            'subramo',
            'agenteWorkspace',
            'vehiculo',
            'documentos',
            'cobranzaCuotas' => fn ($q) => $q->orderBy('fecha_programada')->orderBy('numero_cuota'),
        ]);
    }

    public function update(Request $request, Poliza $poliza)
    {
        $this->requirePermission($request, WorkspacePermissions::POLIZAS_MANAGE);
        $activeWorkspaceId = $this->resolveWorkspaceId($request);
        $this->abortIfOutOfWorkspace($request, $poliza->workspace_id);

        $data = $request->validate([
            'workspace_id' => 'sometimes|required|exists:workspaces,id',
            'contratante_id' => 'sometimes|required|exists:contratantes,id',
            'agente_id' => 'nullable|exists:agente_workspaces,id',
            'aseguradora_id' => 'sometimes|required|exists:aseguradoras,id',
            'ramo_id' => 'sometimes|required|exists:ramos,id',
            'subramo_id' => 'sometimes|required|exists:subramos,id',
            'numero_poliza' => 'sometimes|required|string|max:255',
            'fecha_emision' => 'nullable|date',
            'inicio_vigencia' => 'sometimes|required|date',
            'fin_vigencia' => 'sometimes|required|date',
            'prima_neta' => 'nullable|numeric|min:0',
            'iva' => 'nullable|numeric|min:0',
            'prima_total' => 'nullable|numeric|min:0',
            'moneda' => 'nullable|string|max:10',
            'frecuencia_cobro' => 'nullable|in:unico,mensual,trimestral,semestral,anual',
            'monto_cuota' => 'nullable|numeric|min:0',
            'telefono_notificacion' => 'nullable|string|max:30',
            'vehiculo' => 'nullable|array',
            'vehiculo.marca' => 'nullable|string|max:255',
            'vehiculo.modelo' => 'nullable|string|max:255',
            'vehiculo.anio' => 'nullable|integer|min:1900|max:2100',
            'vehiculo.serie' => 'nullable|string|max:255',
            'vehiculo.placas' => 'nullable|string|max:50',
            'vehiculo.motor' => 'nullable|string|max:255',
            'vehiculo.pasajeros' => 'nullable|integer|min:0',
        ]);

        $workspaceId = $data['workspace_id'] ?? $activeWorkspaceId;
        $contratanteId = $data['contratante_id'] ?? $poliza->contratante_id;
        $ramoId = $data['ramo_id'] ?? $poliza->ramo_id;
        $subramoId = $data['subramo_id'] ?? $poliza->subramo_id;

        if (array_key_exists('contratante_id', $data)) {
            $c = Contratante::findOrFail($contratanteId);
            if ((int) $c->workspace_id !== (int) $workspaceId) {
                return response()->json([
                    'message' => 'El contratante no pertenece al workspace indicado.',
                ], 422);
            }
        }

        if (array_key_exists('agente_id', $data) && $data['agente_id'] !== null) {
            $agenteWorkspace = AgenteWorkspace::query()
                ->with('clavesAseguradora')
                ->findOrFail($data['agente_id']);
            if ((int) $agenteWorkspace->workspace_id !== (int) $workspaceId) {
                return response()->json([
                    'message' => 'El agente no pertenece al workspace indicado.',
                ], 422);
            }

            $aseguradoraId = (int) ($data['aseguradora_id'] ?? $poliza->aseguradora_id);
            $hasClave = $agenteWorkspace->clavesAseguradora
                ->contains('aseguradora_id', $aseguradoraId);

            if (! $hasClave) {
                return response()->json([
                    'message' => 'El agente no tiene clave para la aseguradora seleccionada.',
                ], 422);
            }
        }

        if (array_key_exists('subramo_id', $data) || array_key_exists('ramo_id', $data)) {
            $sub = Subramo::findOrFail($subramoId);
            if ((int) $sub->ramo_id !== (int) $ramoId) {
                return response()->json([
                    'message' => 'El subramo no corresponde al ramo indicado.',
                ], 422);
            }
        }

        $moneyTouched = collect(['prima_neta', 'iva', 'prima_total', 'frecuencia_cobro', 'monto_cuota'])
            ->contains(fn (string $k) => array_key_exists($k, $data));

        if ($moneyTouched) {
            $data = $this->premiumCalculator->apply(
                $data,
                $poliza->prima_neta !== null ? (float) $poliza->prima_neta : null,
                $poliza->prima_total !== null ? (float) $poliza->prima_total : null,
                $poliza->frecuencia_cobro,
            );
        }

        return DB::transaction(function () use ($request, $poliza, $data) {
            $keys = [
                'workspace_id',
                'contratante_id',
                'aseguradora_id',
                'ramo_id',
                'subramo_id',
                'numero_poliza',
                'fecha_emision',
                'inicio_vigencia',
                'fin_vigencia',
                'prima_neta',
                'iva',
                'prima_total',
                'moneda',
                'frecuencia_cobro',
                'monto_cuota',
                'telefono_notificacion',
            ];

            $updates = collect($data)->only($keys)->all();
            if (array_key_exists('agente_id', $data)) {
                $updates['agente_id'] = $data['agente_id'];
            }

            $cuotasKeys = [
                'frecuencia_cobro',
                'monto_cuota',
                'telefono_notificacion',
                'inicio_vigencia',
                'fin_vigencia',
                'prima_neta',
                'iva',
                'prima_total',
            ];
            $shouldSyncCuotas = ! empty(array_intersect(array_keys($updates), $cuotasKeys));
            $shouldSyncVigencia = ! empty(array_intersect(array_keys($updates), [
                'fin_vigencia',
                'telefono_notificacion',
                'contratante_id',
            ]));

            if ($updates !== []) {
                $poliza->update($updates);
            }

            if ($request->has('vehiculo')) {
                if ($request->input('vehiculo') === null) {
                    $poliza->vehiculo?->delete();
                } elseif (is_array($request->input('vehiculo'))) {
                    $raw = $request->input('vehiculo', []);
                    $attrs = array_filter($raw, fn ($v) => $v !== null && $v !== '');
                    PolizaVehiculo::updateOrCreate(
                        ['poliza_id' => $poliza->id],
                        $attrs
                    );
                }
            }

            if ($shouldSyncCuotas) {
                $poliza->refresh();
                $poliza->load('contratante');
                $this->cobranzaCuotasGenerator->syncForPoliza($poliza);
                $this->whatsappReminderPlanner->syncForPoliza($poliza->fresh(), isNewPoliza: false);
            }

            if ($shouldSyncVigencia || $shouldSyncCuotas) {
                $fresh = $poliza->fresh()->loadMissing(['contratante', 'aseguradora', 'workspace']);
                $this->vigenciaReminderPlanner->syncForPoliza($fresh);
                $this->whatsappSender->sendDueForPoliza((int) $fresh->id, includeAlta: false);
            }

            return $poliza->fresh()->load([
                'contratante',
                'aseguradora',
                'ramo',
                'subramo',
                'agenteWorkspace',
                'vehiculo',
                'cobranzaCuotas' => fn ($q) => $q->orderBy('fecha_programada')->orderBy('numero_cuota'),
            ]);
        });
    }

    public function destroy(Poliza $poliza)
    {
        $this->requirePermission(request(), WorkspacePermissions::POLIZAS_MANAGE);
        $this->abortIfOutOfWorkspace(request(), $poliza->workspace_id);
        $poliza->delete();

        return response()->noContent();
    }
}
