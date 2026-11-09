<?php

namespace Tests\Feature;

use App\Models\Aseguradora;
use App\Models\CobranzaCuota;
use App\Models\Contratante;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Poliza;
use App\Models\Ramo;
use App\Models\Role;
use App\Models\Subramo;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\WorkspacePermissions as P;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardCobranzaTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $user;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $pDashboard = Permission::firstOrCreate(['name' => P::DASHBOARD_VIEW], ['group' => 'dashboard', 'description' => 'Ver dashboard']);
        $pCobranza = Permission::firstOrCreate(['name' => P::COBRANZA_VIEW], ['group' => 'cobranza', 'description' => 'Ver cobranza']);

        $this->adminRole = Role::create([
            'nombre' => 'Admin Test',
            'is_system' => true,
        ]);
        $this->adminRole->permissions()->attach([$pDashboard->id, $pCobranza->id]);

        $plan = Plan::create([
            'nombre' => 'Pro',
            'slug' => 'pro',
            'precio_mensual' => 1000,
            'is_public' => true,
        ]);

        $this->user = User::factory()->create();

        $this->workspace = Workspace::create([
            'nombre' => 'Workspace Test',
            'slug' => 'ws-test',
            'plan_id' => $plan->id,
            'owner_id' => $this->user->id,
        ]);

        $this->user->current_workspace_id = $this->workspace->id;
        $this->user->save();

        app(\App\Services\Subscription\SubscriptionService::class)->activatePaid($this->workspace);

        WorkspaceUser::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'role_id' => $this->adminRole->id,
        ]);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $roleNoPerm = Role::create([
            'nombre' => 'Sin permisos',
            'is_system' => true,
        ]);

        $restrictedUser = User::factory()->create();
        $restrictedUser->current_workspace_id = $this->workspace->id;
        $restrictedUser->save();

        WorkspaceUser::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $restrictedUser->id,
            'role_id' => $roleNoPerm->id,
        ]);

        $response = $this->actingAs($restrictedUser)->getJson('/api/dashboard/cobranza');
        $response->assertStatus(403);
    }

    public function test_cobranza_summary_groups_and_priority_cases(): void
    {
        $aseguradora = Aseguradora::create(['nombre' => 'GNP']);
        $ramo = Ramo::create(['nombre' => 'Autos']);
        $subramo = Subramo::create(['ramo_id' => $ramo->id, 'nombre' => 'Autos Residentes']);
        $contratante = Contratante::create([
            'workspace_id' => $this->workspace->id,
            'nombre' => 'Juan Pérez',
            'tipo_persona' => 'Física',
        ]);

        // Póliza 1 (MXN)
        $polizaMxn = Poliza::create([
            'workspace_id' => $this->workspace->id,
            'contratante_id' => $contratante->id,
            'aseguradora_id' => $aseguradora->id,
            'ramo_id' => $ramo->id,
            'subramo_id' => $subramo->id,
            'numero_poliza' => 'POL-MXN-001',
            'fecha_emision' => now(),
            'inicio_vigencia' => now(),
            'fin_vigencia' => now()->addYear(),
            'prima_neta' => 10000,
            'iva' => 1600,
            'prima_total' => 11600,
            'moneda' => 'MXN',
        ]);

        // Póliza 2 (USD)
        $polizaUsd = Poliza::create([
            'workspace_id' => $this->workspace->id,
            'contratante_id' => $contratante->id,
            'aseguradora_id' => $aseguradora->id,
            'ramo_id' => $ramo->id,
            'subramo_id' => $subramo->id,
            'numero_poliza' => 'POL-USD-002',
            'fecha_emision' => now(),
            'inicio_vigencia' => now(),
            'fin_vigencia' => now()->addYear(),
            'prima_neta' => 500,
            'iva' => 80,
            'prima_total' => 580,
            'moneda' => 'USD',
        ]);

        $today = Carbon::today();

        // 1. Vencida MXN (10 días de atraso, estatus pendiente)
        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaMxn->id,
            'numero_cuota' => 1,
            'fecha_programada' => $today->copy()->subDays(10)->toDateString(),
            'monto' => 1000.00,
            'estatus' => 'pendiente',
        ]);

        // 2. Vencida USD (5 días de atraso, estatus vencido)
        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaUsd->id,
            'numero_cuota' => 1,
            'fecha_programada' => $today->copy()->subDays(5)->toDateString(),
            'monto' => 200.00,
            'estatus' => 'vencido',
        ]);

        // 3. Vencida pero PAGADA (debe excluirse)
        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaMxn->id,
            'numero_cuota' => 2,
            'fecha_programada' => $today->copy()->subDays(3)->toDateString(),
            'monto' => 999.00,
            'estatus' => 'pagado',
        ]);

        // 4. Vencida pero CANCELADA (debe excluirse)
        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaMxn->id,
            'numero_cuota' => 3,
            'fecha_programada' => $today->copy()->subDays(2)->toDateString(),
            'monto' => 888.00,
            'estatus' => 'cancelado',
        ]);

        // 5. Vence HOY (MXN)
        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaMxn->id,
            'numero_cuota' => 4,
            'fecha_programada' => $today->toDateString(),
            'monto' => 1500.00,
            'estatus' => 'pendiente',
        ]);

        // 6. Próximos 7 días (a 3 días, MXN)
        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaMxn->id,
            'numero_cuota' => 5,
            'fecha_programada' => $today->copy()->addDays(3)->toDateString(),
            'monto' => 2500.00,
            'estatus' => 'pendiente',
        ]);

        // 7. Lejana (a 20 días, NO debe estar en próximos 7 días)
        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaMxn->id,
            'numero_cuota' => 6,
            'fecha_programada' => $today->copy()->addDays(20)->toDateString(),
            'monto' => 4000.00,
            'estatus' => 'pendiente',
        ]);

        // 8. Cuota de otro workspace (debe excluirse totalmente)
        $otherWs = Workspace::create([
            'nombre' => 'Otro WS',
            'slug' => 'otro-ws',
            'plan_id' => $this->workspace->plan_id,
            'owner_id' => $this->user->id,
        ]);
        CobranzaCuota::create([
            'workspace_id' => $otherWs->id,
            'poliza_id' => $polizaMxn->id,
            'numero_cuota' => 99,
            'fecha_programada' => $today->toDateString(),
            'monto' => 50000.00,
            'estatus' => 'pendiente',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/dashboard/cobranza');

        $response->assertStatus(200);

        $json = $response->json();

        // 1. Vencidas: 2 cuotas (1 MXN de 1000, 1 USD de 200)
        $this->assertEquals(2, $json['vencidas']['total']);
        $this->assertEquals(2, $json['vencidas']['cantidad']);
        $this->assertEquals(1000.00, $json['vencidas']['montos_por_moneda']['MXN']);
        $this->assertEquals(200.00, $json['vencidas']['montos_por_moneda']['USD']);

        // 2. Hoy: 1 cuota (MXN de 1500)
        $this->assertEquals(1, $json['hoy']['total']);
        $this->assertEquals(1, $json['hoy']['cantidad']);
        $this->assertEquals(1500.00, $json['hoy']['montos_por_moneda']['MXN']);

        // 3. Próximos 7 días: 1 cuota (MXN de 2500)
        $this->assertEquals(1, $json['proximos_7_dias']['total']);
        $this->assertEquals(1, $json['proximos_7_dias']['cantidad']);
        $this->assertEquals(2500.00, $json['proximos_7_dias']['montos_por_moneda']['MXN']);

        // 4. Casos prioritarios: hasta 5 casos ordenados por fecha ascendente
        $this->assertCount(5, $json['casos_prioritarios']);
        $primerCaso = $json['casos_prioritarios'][0];
        $this->assertEquals('POL-MXN-001', $primerCaso['poliza']);
        $this->assertEquals('Juan Pérez', $primerCaso['cliente']);
        $this->assertEquals(1000.00, $primerCaso['monto']);
        $this->assertEquals('MXN', $primerCaso['moneda']);
        $this->assertEquals(10, $primerCaso['dias_atraso']);

        $segundoCaso = $json['casos_prioritarios'][1];
        $this->assertEquals('POL-USD-002', $segundoCaso['poliza']);
        $this->assertEquals(200.00, $segundoCaso['monto']);
        $this->assertEquals('USD', $segundoCaso['moneda']);
        $this->assertEquals(5, $segundoCaso['dias_atraso']);
    }

    public function test_cobranza_summary_respects_filters(): void
    {
        $aseguradoraA = Aseguradora::create(['nombre' => 'Axa']);
        $aseguradoraB = Aseguradora::create(['nombre' => 'Qualitas']);
        $ramo = Ramo::create(['nombre' => 'Gastos Médicos']);
        $subramo = Subramo::create(['ramo_id' => $ramo->id, 'nombre' => 'Mayor']);
        $contratante = Contratante::create([
            'workspace_id' => $this->workspace->id,
            'nombre' => 'Cliente A',
            'tipo_persona' => 'Física',
        ]);

        $polizaA = Poliza::create([
            'workspace_id' => $this->workspace->id,
            'contratante_id' => $contratante->id,
            'aseguradora_id' => $aseguradoraA->id,
            'ramo_id' => $ramo->id,
            'subramo_id' => $subramo->id,
            'numero_poliza' => 'POL-A',
            'fecha_emision' => now(),
            'inicio_vigencia' => now(),
            'fin_vigencia' => now()->addYear(),
            'prima_neta' => 1000,
            'iva' => 160,
            'prima_total' => 1160,
            'moneda' => 'MXN',
        ]);

        $polizaB = Poliza::create([
            'workspace_id' => $this->workspace->id,
            'contratante_id' => $contratante->id,
            'aseguradora_id' => $aseguradoraB->id,
            'ramo_id' => $ramo->id,
            'subramo_id' => $subramo->id,
            'numero_poliza' => 'POL-B',
            'fecha_emision' => now(),
            'inicio_vigencia' => now(),
            'fin_vigencia' => now()->addYear(),
            'prima_neta' => 2000,
            'iva' => 320,
            'prima_total' => 2320,
            'moneda' => 'MXN',
        ]);

        $today = Carbon::today();

        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaA->id,
            'numero_cuota' => 1,
            'fecha_programada' => $today->copy()->subDays(2)->toDateString(),
            'monto' => 500.00,
            'estatus' => 'pendiente',
        ]);

        CobranzaCuota::create([
            'workspace_id' => $this->workspace->id,
            'poliza_id' => $polizaB->id,
            'numero_cuota' => 1,
            'fecha_programada' => $today->copy()->subDays(2)->toDateString(),
            'monto' => 800.00,
            'estatus' => 'pendiente',
        ]);

        // Filtrar por aseguradora A
        $responseA = $this->actingAs($this->user)->getJson('/api/dashboard/cobranza?aseguradora_id=' . $aseguradoraA->id);
        $responseA->assertStatus(200);
        $this->assertEquals(1, $responseA->json('vencidas.total'));
        $this->assertEquals(500.00, $responseA->json('vencidas.montos_por_moneda.MXN'));

        // Filtrar por aseguradora B
        $responseB = $this->actingAs($this->user)->getJson('/api/dashboard/cobranza?aseguradora_id=' . $aseguradoraB->id);
        $responseB->assertStatus(200);
        $this->assertEquals(1, $responseB->json('vencidas.total'));
        $this->assertEquals(800.00, $responseB->json('vencidas.montos_por_moneda.MXN'));

        // Filtrar por ramo
        $responseRamo = $this->actingAs($this->user)->getJson('/api/dashboard/cobranza?ramo_id=' . $ramo->id);
        $responseRamo->assertStatus(200);
        $this->assertEquals(2, $responseRamo->json('vencidas.total'));

        // Ramo inexistente
        $responseRamoEmpty = $this->actingAs($this->user)->getJson('/api/dashboard/cobranza?ramo_id=999999');
        $responseRamoEmpty->assertStatus(200);
        $this->assertEquals(0, $responseRamoEmpty->json('vencidas.total'));
    }
}
