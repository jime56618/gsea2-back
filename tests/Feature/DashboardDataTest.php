<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\AgenteWorkspace;
use App\Models\Aseguradora;
use App\Models\CalendarEvent;
use App\Models\Contratante;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Poliza;
use App\Models\Ramo;
use App\Models\Role;
use App\Models\Subramo;
use App\Models\Tramite;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Subscription\SubscriptionService;
use App\Support\WorkspacePermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $ws;

    private Workspace $other;

    private Role $role;

    private array $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Carbon::setTestNow(Carbon::parse('2026-10-17 02:00:00', 'UTC'));
        $this->user = User::factory()->create();
        $plan = Plan::create(['nombre' => 'Pro', 'slug' => 'dashboard-test', 'precio_mensual' => 1000, 'is_public' => true]);
        $this->ws = Workspace::create(['nombre' => 'Actual', 'slug' => 'actual', 'owner_id' => $this->user->id, 'plan_id' => $plan->id]);
        $this->other = Workspace::create(['nombre' => 'Ajeno', 'slug' => 'ajeno', 'owner_id' => $this->user->id, 'plan_id' => $plan->id]);
        app(SubscriptionService::class)->activatePaid($this->ws);
        $this->role = Role::create(['nombre' => 'Dashboard', 'is_system' => true]);
        foreach ([P::DASHBOARD_VIEW, P::POLIZAS_VIEW, P::TRAMITES_VIEW, P::CALENDAR_VIEW] as $permission) {
            $p = Permission::firstOrCreate(['name' => $permission], ['group' => 'test', 'description' => 'Test']);
            $this->role->permissions()->attach($p->id);
        }
        WorkspaceUser::create(['workspace_id' => $this->ws->id, 'user_id' => $this->user->id, 'role_id' => $this->role->id]);
        $this->user->update(['current_workspace_id' => $this->ws->id]);
        $aseguradora = Aseguradora::create(['nombre' => 'Test']);
        $ramo = Ramo::create(['nombre' => 'Test']);
        $subramo = Subramo::create(['nombre' => 'Test', 'ramo_id' => $ramo->id]);
        $cliente = Contratante::create(['workspace_id' => $this->ws->id, 'nombre' => 'Cliente', 'tipo_persona' => 'Fisica']);
        $this->policy = [
            'workspace_id' => $this->ws->id, 'contratante_id' => $cliente->id,
            'aseguradora_id' => $aseguradora->id, 'ramo_id' => $ramo->id, 'subramo_id' => $subramo->id,
            'numero_poliza' => 'TEST', 'fecha_emision' => '2026-10-01', 'inicio_vigencia' => '2026-01-01',
            'fin_vigencia' => '2026-10-16', 'prima_total' => 100, 'moneda' => 'MXN',
        ];
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ranges_totals_currency_and_emission_date_are_independent_of_pagination(): void
    {
        foreach ([-1, 0, 7, 8, 15, 16, 30, 31] as $days) {
            Poliza::create(array_merge($this->policy, ['numero_poliza' => 'TEST-'.$days, 'fin_vigencia' => Carbon::today('America/Merida')->addDays($days)->toDateString()]));
        }
        Poliza::create(array_merge($this->policy, ['workspace_id' => $this->other->id, 'prima_total' => 9999]));
        Poliza::create(array_merge($this->policy, ['numero_poliza' => 'USD', 'moneda' => 'USD', 'fecha_emision' => '2026-10-16', 'prima_total' => 50]));
        Poliza::create(array_merge($this->policy, ['numero_poliza' => 'SIN-FECHA', 'fecha_emision' => null, 'prima_total' => 9999]));
        $response = $this->getJson('/api/dashboard/summary?from=2026-10-01&to=2026-10-16&per_page=1&rango=8_15');
        $response->assertOk()->assertJsonPath('fecha_corte', '2026-10-16')
            ->assertJsonPath('polizas_por_vencer.cantidades.0_7', 4)
            ->assertJsonPath('polizas_por_vencer.cantidades.8_15', 2)
            ->assertJsonPath('polizas_por_vencer.cantidades.16_30', 2)
            ->assertJsonPath('polizas_por_vencer.listado.total', 2)
            ->assertJsonCount(1, 'polizas_por_vencer.listado.data')
            ->assertJsonPath('resumen_periodo.polizas_emitidas', 9)
            ->assertJsonPath('resumen_periodo.por_moneda.0.moneda', 'MXN')
            ->assertJsonPath('resumen_periodo.por_moneda.0.polizas_emitidas', 8)
            ->assertJsonPath('resumen_periodo.por_moneda.1.moneda', 'USD');
        $this->assertEquals(800, $response->json('resumen_periodo.por_moneda.0.prima_total'));
        $this->assertEquals(50, $response->json('resumen_periodo.por_moneda.1.prima_total'));
        $otherInsurer = Aseguradora::create(['nombre' => 'Otra']);
        $this->getJson('/api/dashboard/summary?aseguradora_id='.$otherInsurer->id)
            ->assertOk()->assertJsonPath('resumen_periodo.polizas_emitidas', 0);
    }

    public function test_tramites_preserve_real_values_and_totals(): void
    {
        foreach (['Revision', 'Revision', 'Emitido'] as $stage) {
            Tramite::create(['workspace_id' => $this->ws->id, 'etapa' => $stage, 'semaforo' => 'rojo', 'folio' => 'F', 'fecha_ultima_modificacion' => now()]);
        }
        Tramite::create(['workspace_id' => $this->other->id, 'etapa' => 'Ajena']);
        $this->getJson('/api/dashboard/summary?etapas_pendientes[]=Revision&per_page=1')
            ->assertOk()->assertJsonPath('tramites.pendientes_identificados', true)
            ->assertJsonPath('tramites.total', 2)->assertJsonPath('tramites.listado.total', 2)
            ->assertJsonCount(1, 'tramites.listado.data')->assertJsonPath('tramites.listado.data.0.semaforo', 'rojo');
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('tramites.pendientes_identificados', false)
            ->assertJsonPath('tramites.criterio_prioridad', null);
        $this->getJson('/api/dashboard/summary?etapas_pendientes[]=Ajena')->assertUnprocessable();
    }

    public function test_calendar_local_boundaries_overlap_permissions_and_pagination(): void
    {
        foreach ([
            ['2026-10-15 23:00:00', '2026-10-16 01:00:00'],
            ['2026-10-16 23:59:59', null],
            ['2026-10-17 00:00:00', null],
            ['2026-10-23 23:59:59', null],
            ['2026-10-24 00:00:00', null],
        ] as [$start, $end]) {
            CalendarEvent::create(['workspace_id' => $this->ws->id, 'user_id' => $this->user->id, 'title' => 'Evento', 'starts_at' => $start, 'ends_at' => $end, 'type' => 'meeting', 'meet_link' => 'https://meet.google.com/test']);
        }
        CalendarEvent::create(['workspace_id' => $this->other->id, 'user_id' => $this->user->id, 'title' => 'Ajeno', 'starts_at' => '2026-10-16 12:00:00']);
        $this->getJson('/api/calendar/events?agenda=hoy&per_page=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonCount(1, 'listado.data')
            ->assertJsonPath('listado.data.0.starts_at', '2026-10-15T23:00:00-06:00')
            ->assertJsonPath('listado.data.0.meet_link', 'https://meet.google.com/test');
        $this->getJson('/api/calendar/events?agenda=proximos_7_dias')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/calendar/events?from=2026-10-23&to=2026-10-23')->assertOk()->assertJsonPath('total', 1);
        $this->role->permissions()->detach(Permission::where('name', P::CALENDAR_VIEW)->value('id'));
        $this->getJson('/api/calendar/events?agenda=hoy')->assertForbidden();
    }

    public function test_filters_workspace_and_permissions_are_validated(): void
    {
        foreach (['from=2026-10-20&to=2026-10-01', 'from=2026-02-30&to=2026-03-01', 'from=2026-10-01', 'per_page=101', 'polizas_page=0', 'rango=bad', 'agente_id=999999', 'workspace_id='.$this->other->id] as $query) {
            $this->getJson('/api/dashboard/summary?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/calendar/events?from=bad&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/calendar/events?workspace_id='.$this->other->id)->assertUnprocessable();
        $this->role->permissions()->detach(Permission::where('name', P::POLIZAS_VIEW)->value('id'));
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('resumen_periodo', null)->assertJsonPath('polizas_por_vencer', null);
        $this->role->permissions()->detach(Permission::where('name', P::DASHBOARD_VIEW)->value('id'));
        $this->getJson('/api/dashboard/summary')->assertForbidden();
        $this->user->update(['current_workspace_id' => $this->other->id]);
        $this->getJson('/api/dashboard/summary')->assertForbidden();
    }

    public function test_agent_filter_uses_workspace_assignment_and_rejects_foreign_assignments(): void
    {
        $agent = Agente::create(['nombre' => 'Agente']);
        $local = AgenteWorkspace::create(['workspace_id' => $this->ws->id, 'agente_id' => $agent->id]);
        $foreign = AgenteWorkspace::create(['workspace_id' => $this->other->id, 'agente_id' => $agent->id]);
        Poliza::create(array_merge($this->policy, ['agente_id' => $local->id]));
        $this->getJson('/api/dashboard/summary?agente_id='.$foreign->id)->assertUnprocessable();
    }

    public function test_dashboard_query_count(): void
    {
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/dashboard/summary?compact=1');
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        $this->assertLessThanOrEqual(18, count($queries));
    }
}

