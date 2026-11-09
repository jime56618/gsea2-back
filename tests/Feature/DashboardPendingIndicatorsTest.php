<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWorkspaceNotLocked;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tramite;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Rules\PolizaAnteriorValida;
use App\Support\WorkspacePermissions as P;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Solo SQLite :memory:. No usa RefreshDatabase ni ejecuta migraciones o seeders. */
class DashboardPendingIndicatorsTest extends TestCase
{
    private User $user;
    private Role $role;
    private Workspace $ws;
    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();
        // Fallar antes de cualquier acceso a una base si el entorno no es aislado.
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutMiddleware(EnsureWorkspaceNotLocked::class);
        $this->createIsolatedSchema();
        $this->user = User::create(['name' => 'Test', 'email' => 'test@example.test', 'password' => 'test-password']);
        $this->ws = Workspace::create(['nombre' => 'Actual', 'slug' => 'actual', 'owner_id' => $this->user->id]);
        $this->other = Workspace::create(['nombre' => 'Ajeno', 'slug' => 'ajeno', 'owner_id' => $this->user->id]);
        $this->role = Role::create(['nombre' => 'Administrador', 'slug' => 'admin', 'is_system' => true]);
        foreach ([P::DASHBOARD_VIEW, P::TRAMITES_VIEW, P::TRAMITES_MANAGE, P::METAS_CREATE, P::METAS_UPDATE] as $name) {
            $p = Permission::create(['name' => $name, 'group' => 'test', 'description' => 'test']);
            $this->role->permissions()->attach($p->id);
        }
        WorkspaceUser::create(['workspace_id' => $this->ws->id, 'user_id' => $this->user->id, 'role_id' => $this->role->id]);
        $this->user->update(['current_workspace_id' => $this->ws->id]);
        $this->actingAs($this->user);
    }

    private function createIsolatedSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
            $t->unsignedBigInteger('current_workspace_id')->nullable(); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('slug'); $t->unsignedBigInteger('owner_id'); $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('slug')->nullable(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->boolean('is_system')->default(false); $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique(); $t->string('group'); $t->string('description'); $t->timestamps();
        });
        Schema::create('workspace_user', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('role_id'); $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('permission_id'); $t->unsignedBigInteger('role_id'); $t->timestamps();
        });
        Schema::create('tramites', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('estado')->nullable();
            foreach (['folio', 'tipo', 'tipo_tramite', 'etapa', 'semaforo'] as $name) $t->string($name)->nullable();
            $t->dateTime('fecha_ultima_modificacion')->nullable(); $t->timestamps();
        });
        Schema::create('polizas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('poliza_anterior_id')->nullable();
            $t->string('moneda')->nullable(); $t->decimal('prima_total', 16, 2)->nullable();
            $t->date('fecha_emision')->nullable();
            $t->date('fin_vigencia')->nullable(); $t->date('inicio_vigencia')->nullable(); $t->string('numero_poliza')->nullable();
            foreach (['contratante_id','aseguradora_id','ramo_id','agente_id'] as $key) $t->unsignedBigInteger($key)->nullable();
        });
        Schema::create('contratantes', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('nombre'); });
        foreach (['ramos', 'aseguradoras'] as $name) Schema::create($name, function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT', fn ($date, $format) => $date ? substr($date, 0, 7) : null, 2);
        Schema::create('metas_comerciales', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->integer('anio'); $t->integer('mes');
            $t->string('moneda'); $t->decimal('importe_objetivo', 16, 2); $t->timestamps();
            $t->unique(['workspace_id', 'anio', 'mes', 'moneda']);
        });
    }

    public function test_tramite_state_is_independent_and_historical_state_is_preserved(): void
    {
        $old = Tramite::create(['workspace_id' => $this->ws->id, 'etapa' => 'Emisión', 'semaforo' => 'verde']);
        $this->putJson('/api/tramites/'.$old->id, ['etapa' => 'Revisión'])->assertOk()->assertJsonPath('estado', null);
        $this->postJson('/api/tramites', ['etapa' => 'Revisión', 'semaforo' => 'rojo'])->assertCreated()
            ->assertJsonPath('estado', 'en_atencion')->assertJsonPath('semaforo', 'rojo');
        $this->postJson('/api/tramites', ['estado' => 'finalizado', 'semaforo' => 'rojo'])->assertCreated();
        $this->postJson('/api/tramites', ['estado' => 'cancelado', 'semaforo' => 'amarillo'])->assertCreated();
        Tramite::create(['workspace_id' => $this->other->id, 'estado' => 'en_atencion', 'semaforo' => 'verde']);
        $this->getJson('/api/dashboard/summary')->assertOk()
            ->assertJsonPath('tramites.estados.en_atencion', 1)->assertJsonPath('tramites.estados.sin_estado', 1)
            ->assertJsonPath('tramites.total', 4)->assertJsonCount(3, 'tramites.totales_por_semaforo');
        $this->getJson('/api/tramites?estado=en_atencion')->assertOk()->assertJsonPath('total', 1);
        $this->putJson('/api/tramites/'.$old->id, ['estado' => 'cerrado'])->assertUnprocessable();
        $this->putJson('/api/tramites/'.$old->id, ['estado' => null])->assertUnprocessable();
        $this->postJson('/api/tramites', ['estado' => 'verde'])->assertUnprocessable();
        $foreign = Tramite::where('workspace_id', $this->other->id)->firstOrFail();
        $this->putJson('/api/tramites/'.$foreign->id, ['estado' => 'finalizado'])->assertForbidden();
        $this->removePermission(P::TRAMITES_VIEW);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('tramites', null);
    }

    public function test_unmigrated_tramites_remain_usable_without_fabricated_statistics(): void
    {
        Schema::table('tramites', fn (Blueprint $t) => $t->dropColumn('estado'));
        $this->postJson('/api/tramites', ['semaforo' => 'verde'])->assertCreated();
        $this->getJson('/api/tramites')->assertOk()->assertJsonPath('estado_disponible', false);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('tramites.estados', null);
        $this->getJson('/api/dashboard/summary?compact=1')->assertOk()->assertJsonPath('tramites.sin_estado_conocido', 1);
        $this->postJson('/api/tramites', ['estado' => 'en_atencion'])->assertStatus(503);
    }

    public function test_goals_are_unique_scoped_and_match_emission_month_and_currency(): void
    {
        $payload = ['anio' => 2026, 'mes' => 10, 'moneda' => 'MXN', 'importe_objetivo' => '200.00'];
        $id = $this->postJson('/api/dashboard/metas-comerciales', $payload)->assertCreated()->json('id');
        $this->postJson('/api/dashboard/metas-comerciales', $payload)->assertUnprocessable();
        $this->postJson('/api/dashboard/metas-comerciales', array_merge($payload, ['importe_objetivo' => 0, 'moneda' => 'USD']))->assertUnprocessable();
        foreach ([
            [$this->ws->id, 'MXN', 150, '2026-10-01'], [$this->ws->id, 'MXN', 100, '2026-10-31'],
            [$this->ws->id, 'USD', 5000, '2026-10-01'], [$this->ws->id, 'MXN', 9000, '2026-11-01'],
            [$this->other->id, 'MXN', 9000, '2026-10-01'], [$this->ws->id, 'MXN', 9000, null],
        ] as [$ws, $currency, $amount, $date]) {
            DB::table('polizas')->insert(['workspace_id' => $ws, 'moneda' => $currency, 'prima_total' => $amount, 'fecha_emision' => $date]);
        }
        $r = $this->getJson('/api/dashboard/metas-comerciales?anio=2026&mes=10')->assertOk();
        $this->assertEquals(250, $r->json('metas.0.prima_emitida'));
        $this->assertEquals(125, $r->json('metas.0.cumplimiento_pct'));
        $r->assertJsonPath('metas.0.polizas_emitidas', 2);
        $this->getJson('/api/dashboard/metas-comerciales?anio=2026&mes=11')->assertOk()->assertJsonCount(0, 'metas');
        $this->putJson('/api/dashboard/metas-comerciales/'.$id, ['importe_objetivo' => 300])->assertOk();
        $foreign = DB::table('metas_comerciales')->insertGetId(['workspace_id' => $this->other->id, 'anio' => 2026, 'mes' => 10, 'moneda' => 'MXN', 'importe_objetivo' => 400]);
        $this->putJson('/api/dashboard/metas-comerciales/'.$foreign, ['importe_objetivo' => 10])->assertNotFound();
        $this->getJson('/api/dashboard/metas-comerciales?workspace_id='.$this->other->id)->assertUnprocessable();
    }

    public function test_goal_management_requires_admin_and_specific_permission_but_read_only_needs_dashboard(): void
    {
        $payload = ['anio' => 2026, 'mes' => 10, 'moneda' => 'MXN', 'importe_objetivo' => 100];
        $id = $this->postJson('/api/dashboard/metas-comerciales', $payload)->assertCreated()->json('id');
        $this->role->update(['slug' => 'operador', 'nombre' => 'Operador']);
        $this->getJson('/api/dashboard/metas-comerciales')->assertOk()->assertJsonPath('puede_crear', false);
        $this->postJson('/api/dashboard/metas-comerciales', $payload)->assertForbidden();
        $this->putJson('/api/dashboard/metas-comerciales/'.$id, ['importe_objetivo' => 200])->assertForbidden();
        $this->role->update(['slug' => 'admin', 'nombre' => 'Administrador']);
        $this->removePermission(P::METAS_CREATE);
        $this->postJson('/api/dashboard/metas-comerciales', $payload)->assertForbidden();
        $this->removePermission(P::METAS_UPDATE);
        $this->putJson('/api/dashboard/metas-comerciales/'.$id, ['importe_objetivo' => 200])->assertForbidden();
        $this->removePermission(P::DASHBOARD_VIEW);
        $this->getJson('/api/dashboard/metas-comerciales')->assertForbidden();
    }

    public function test_unmigrated_goals_have_explicit_unavailable_contract(): void
    {
        Schema::drop('metas_comerciales');
        $this->getJson('/api/dashboard/metas-comerciales')->assertOk()
            ->assertJsonPath('disponible', false)->assertJsonPath('puede_crear', false)->assertJsonCount(0, 'metas');
        $this->postJson('/api/dashboard/metas-comerciales', ['importe_objetivo' => 100])->assertStatus(503);
    }

    public function test_renewal_rule_rejects_self_cycles_missing_and_foreign_ancestors(): void
    {
        $one = DB::table('polizas')->insertGetId(['workspace_id' => $this->ws->id]);
        $two = DB::table('polizas')->insertGetId(['workspace_id' => $this->ws->id, 'poliza_anterior_id' => $one]);
        $foreign = DB::table('polizas')->insertGetId(['workspace_id' => $this->other->id]);
        $this->assertSame([], $this->renewalErrors($two));
        $this->assertNotEmpty($this->renewalErrors($one, $one));
        $this->assertNotEmpty($this->renewalErrors($two, $one));
        $this->assertNotEmpty($this->renewalErrors($foreign));
        $this->assertNotEmpty($this->renewalErrors(999999));
        DB::table('polizas')->where('id', $one)->update(['poliza_anterior_id' => $two]);
        $this->assertNotEmpty($this->renewalErrors($two));
        DB::table('polizas')->where('id', $one)->update(['poliza_anterior_id' => $foreign]);
        $this->assertNotEmpty($this->renewalErrors($two));
        Schema::table('polizas', fn (Blueprint $t) => $t->dropColumn('poliza_anterior_id'));
        $this->assertNotEmpty($this->renewalErrors($one));
    }

    private function renewalErrors(int $previous, ?int $current = null): array
    {
        $errors = [];
        (new PolizaAnteriorValida($this->ws->id, $current))->validate('poliza_anterior_id', $previous, function ($message) use (&$errors) { $errors[] = $message; });
        return $errors;
    }

    private function removePermission(string $name): void
    {
        $this->role->permissions()->detach(Permission::where('name', $name)->value('id'));
    }

    public function test_compact_summary_preserves_aggregates_and_uses_fewer_queries(): void
    {
        foreach ([P::POLIZAS_VIEW, P::CONTRATANTES_VIEW] as $name) {
            $p = Permission::create(['name'=>$name,'group'=>'test','description'=>'test']); $this->role->permissions()->attach($p->id);
        }
        DB::table('contratantes')->insert([['workspace_id'=>$this->ws->id,'nombre'=>'Actual'],['workspace_id'=>$this->other->id,'nombre'=>'Ajeno']]);
        DB::table('polizas')->insert([
            ['workspace_id'=>$this->ws->id,'numero_poliza'=>'Próxima','moneda'=>'MXN','prima_total'=>100,'fecha_emision'=>now()->toDateString(),'fin_vigencia'=>now()->addDay()->toDateString()],
            ['workspace_id'=>$this->ws->id,'numero_poliza'=>'Vencida','moneda'=>'USD','prima_total'=>50,'fecha_emision'=>now()->toDateString(),'fin_vigencia'=>'2000-01-01'],
            ['workspace_id'=>$this->other->id,'numero_poliza'=>'Ajena','moneda'=>'MXN','prima_total'=>99999,'fecha_emision'=>now()->toDateString(),'fin_vigencia'=>'2000-01-01'],
        ]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $full = $this->getJson('/api/dashboard/summary')->assertOk()->json(); $fullCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $compact = $this->getJson('/api/dashboard/summary?compact=1')->assertOk()->assertJsonPath('polizas_por_vencer.listado',null)->assertJsonPath('tramites.listado',null)->json();
        $this->assertLessThan($fullCount, count(DB::getQueryLog()));
        unset($full['polizas_por_vencer']['listado'],$full['tramites']['listado'],$compact['polizas_por_vencer']['listado'],$compact['tramites']['listado']);
        $this->assertSame($full,$compact);
        $this->getJson('/api/dashboard/totales')->assertOk()->assertJsonPath('clientes_total',1)->assertJsonPath('polizas_vencidas',1);
        $this->getJson('/api/dashboard/totales?workspace_id='.$this->other->id)->assertUnprocessable();
        $this->removePermission(P::CONTRATANTES_VIEW); $this->removePermission(P::POLIZAS_VIEW);
        $this->getJson('/api/dashboard/totales')->assertOk()->assertJsonPath('clientes_total',null)->assertJsonPath('polizas_vencidas',null);
        $this->removePermission(P::DASHBOARD_VIEW);
        $this->getJson('/api/dashboard/totales')->assertForbidden();
    }
}
