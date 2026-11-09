<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWorkspaceNotLocked;
use App\Models\User;
use App\Services\Exports\XlsxExporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Todas las escrituras de fixtures están limitadas a SQLite :memory:. No ejecuta migraciones ni seeders. */
class RecordExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutMiddleware(EnsureWorkspaceNotLocked::class);
        $this->table('users', ['name','email','password','current_workspace_id']);
        $this->table('workspaces', ['nombre']);
        $this->table('roles', ['nombre','slug','workspace_id','is_system']);
        $this->table('permissions', ['name','group','description']);
        $this->table('workspace_user', ['user_id','workspace_id','role_id']);
        $this->table('permission_role', ['permission_id','role_id']);
        $this->table('tramites', ['workspace_id','folio','etapa','estado','semaforo','nombre_agente','ramo','poliza_referencia','fecha_alta','fecha_ultima_modificacion']);
        $this->table('contratantes', ['workspace_id','nombre','rfc','email','telefono','direccion','password']);
        $this->table('aseguradoras', ['nombre']);
        $this->table('agentes', ['nombre','apellido','cedula','email','telefono','ciudad','estado','curp','rfc','direccion','fecha_nacimiento','fecha_alta']);
        $this->table('agente_workspaces', ['workspace_id','agente_id','status']);
        $this->table('agente_workspace_aseguradora', ['agente_workspace_id','aseguradora_id','clave_agente']);
        $this->table('polizas', ['workspace_id','contratante_id','aseguradora_id','agente_id','numero_poliza','inicio_vigencia','fin_vigencia','fecha_emision','prima_total','prima_neta','iva','moneda']);
        $this->table('cobranza_cuotas', ['workspace_id','poliza_id','numero_cuota','fecha_programada','monto','estatus']);
        DB::table('users')->insert(['id'=>1,'name'=>'Test','email'=>'export@example.test','current_workspace_id'=>1]);
        DB::table('workspaces')->insert([['id'=>1,'nombre'=>'Actual'],['id'=>2,'nombre'=>'Ajeno']]);
        DB::table('roles')->insert(['id'=>1,'nombre'=>'Consulta','slug'=>'consulta']);
        DB::table('workspace_user')->insert(['user_id'=>1,'workspace_id'=>1,'role_id'=>1]);
        foreach (['tramites.view','contratantes.view','agentes.view','polizas.view','cobranza.view'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name'=>$name]);
            DB::table('permission_role')->insert(['role_id'=>1,'permission_id'=>$id]);
        }
        $this->actingAs(User::findOrFail(1));
    }

    private function table(string $name, array $fields): void
    {
        Schema::create($name, function (Blueprint $t) use ($fields) {
            $t->id(); foreach ($fields as $field) $t->string($field)->nullable(); $t->timestamps();
        });
    }

    private function sheet($response): string
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $zip = new \ZipArchive(); $this->assertTrue($zip->open($path) === true);
            $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
            $this->assertNotFalse($zip->getFromName('xl/workbook.xml'));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
            $this->assertIsString($sheet); $this->assertNotFalse(simplexml_load_string($sheet));
            return $sheet;
        } finally { @unlink($path); }
    }

    public function test_empty_search_and_direct_export_with_one_many_or_no_selected_ids(): void
    {
        foreach (['tramites','clientes','agentes','polizas','cobranza'] as $module) {
            foreach ([[], ['q'=>''], ['q'=>null]] as $filters) {
                $this->postJson('/api/exports/'.$module.'/count', ['workspace_id'=>1,'filters'=>$filters])->assertOk()->assertJsonPath('total',0);
            }
            foreach ([['q'=>['bad']], ['q'=>['value'=>'bad']], ['q'=>123]] as $filters) {
                $this->postJson('/api/exports/'.$module.'/count', ['workspace_id'=>1,'filters'=>$filters])->assertUnprocessable()->assertJsonValidationErrors('filters.q');
            }
        }
        DB::table('tramites')->insert([
            ['id'=>1,'workspace_id'=>1,'folio'=>'UNO','etapa'=>'Revisión','semaforo'=>'verde'],
            ['id'=>2,'workspace_id'=>1,'folio'=>'DOS','etapa'=>'Revisión','semaforo'=>'rojo'],
        ]);
        foreach ([[1], [1,2]] as $ids) {
            $xml = $this->sheet($this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'selected','ids'=>$ids,'filters'=>['q'=>'']]));
            $this->assertSame(count($ids)+1,substr_count($xml,'<row '));
        }
        $xml = $this->sheet($this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'filtered','ids'=>[],'filters'=>['q'=>'']]));
        $this->assertSame(3,substr_count($xml,'<row '));
        $xml = $this->sheet($this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'filtered','ids'=>[],'filters'=>['q'=>'DOS']]));
        $this->assertSame(2,substr_count($xml,'<row '));
        $this->assertStringNotContainsString('UNO',$xml);
    }

    public function test_tramites_modes_cross_page_filters_permissions_and_workspace(): void
    {
        for ($i=1; $i<=521; $i++) DB::table('tramites')->insert(['workspace_id'=>1,'folio'=>'F-'.$i,'etapa'=>'Aprobación','semaforo'=>$i%2 ? 'verde' : 'rojo','estado'=>null]);
        $foreign = DB::table('tramites')->insertGetId(['workspace_id'=>2,'folio'=>'NO-EXPORTAR','semaforo'=>'verde']);
        $this->postJson('/api/exports/tramites/count', ['workspace_id'=>1,'ids'=>[1,$foreign],'filters'=>['semaforo'=>'verde']])
            ->assertOk()->assertJsonPath('total',1)->assertJsonPath('accessible_ids',[1]);
        $this->postJson('/api/exports/tramites/count', ['workspace_id'=>1,'filters'=>['semaforo'=>'verde']])->assertOk()->assertJsonPath('total',261);
        $sheet = $this->sheet($this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'selected','ids'=>[1,521]]));
        $this->assertSame(3, substr_count($sheet, '<row '));
        $this->assertStringContainsString('F-521',$sheet); $this->assertStringNotContainsString('NO-EXPORTAR',$sheet);
        $sheet = $this->sheet($this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'filtered','filters'=>['semaforo'=>'verde']]));
        $this->assertSame(262, substr_count($sheet,'<row '));
        $sheet = $this->sheet($this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'selected','selection_all'=>true,'excluded_ids'=>['1'],'filters'=>['semaforo'=>'verde']]));
        $this->assertSame(261, substr_count($sheet,'<row '));
        $sheet = $this->sheet($this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'all','filters'=>['q'=>'no-coincide']]));
        $this->assertSame(522, substr_count($sheet,'<row '));
        $this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'selected','ids'=>[$foreign]])->assertUnprocessable();
        $this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'selected','ids'=>[2],'filters'=>['semaforo'=>'verde']])->assertUnprocessable();
        $this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'filtered','filters'=>['q'=>'no-coincide']])->assertUnprocessable();
        $this->postJson('/api/exports/tramites', ['workspace_id'=>2,'mode'=>'all'])->assertUnprocessable();
        DB::table('permission_role')->delete();
        $this->postJson('/api/exports/tramites', ['workspace_id'=>1,'mode'=>'all'])->assertForbidden();
        $this->postJson('/api/exports/tramites/count', ['workspace_id'=>1])->assertForbidden();
    }

    public function test_xlsx_text_is_not_formula_and_dates_and_amounts_are_typed(): void
    {
        $exporter = new XlsxExporter();
        $path = $exporter->write([['text'=>'=HYPERLINK("bad")','amount'=>125.5,'date'=>'2026-10-28','currency'=>'USD'],
            ['text'=>"\t+SUM(1,2)",'amount'=>20,'date'=>null,'currency'=>'MXN']],
            ['Texto'=>['text','text'],'Importe'=>['amount','number'],'Fecha'=>['date','date'],'Moneda'=>['currency','text']]);
        try {
            $zip = new \ZipArchive(); $zip->open($path); $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
            $styles=$zip->getFromName('xl/styles.xml'); $zip->close();
            $this->assertStringNotContainsString('<f', $xml);
            $this->assertStringContainsString('=HYPERLINK', $xml);
            $this->assertStringContainsString('<v>125.5</v>', $xml);
            $this->assertStringContainsString('dd/mm/yyyy', $styles);
            $this->assertStringContainsString('USD',$xml); $this->assertStringContainsString('MXN',$xml);
        } finally { @unlink($path); }
    }

    public function test_other_modules_filters_all_and_selected_are_scoped_and_read_only(): void
    {
        DB::table('contratantes')->insert([
            ['id'=>1,'workspace_id'=>1,'nombre'=>'Ana','email'=>'ana@example.test','telefono'=>'555','password'=>'NO-EXPORTAR-SECRETO'],
            ['id'=>2,'workspace_id'=>2,'nombre'=>'Cliente ajeno','email'=>'other@example.test','telefono'=>'444','password'=>'NO-EXPORTAR-SECRETO'],
        ]);
        DB::table('aseguradoras')->insert(['id'=>1,'nombre'=>'Aseguradora Uno']);
        DB::table('agentes')->insert(['id'=>1,'nombre'=>'Luis','apellido'=>'Pérez','cedula'=>'ABC','email'=>'luis@example.test']);
        DB::table('agente_workspaces')->insert([['id'=>1,'workspace_id'=>1,'agente_id'=>1,'status'=>'activo'],['id'=>2,'workspace_id'=>2,'agente_id'=>1,'status'=>'inactivo']]);
        DB::table('agente_workspace_aseguradora')->insert(['agente_workspace_id'=>1,'aseguradora_id'=>1,'clave_agente'=>'NO-EXPORTAR-CLAVE']);
        DB::table('polizas')->insert([
            ['id'=>1,'workspace_id'=>1,'contratante_id'=>1,'aseguradora_id'=>1,'agente_id'=>1,'numero_poliza'=>'POL-A','inicio_vigencia'=>'2026-01-01','fin_vigencia'=>'2026-12-31','moneda'=>'USD','prima_total'=>125.5],
            ['id'=>2,'workspace_id'=>2,'contratante_id'=>2,'aseguradora_id'=>1,'agente_id'=>2,'numero_poliza'=>'POL-AJENA','inicio_vigencia'=>'2026-01-01','fin_vigencia'=>'2026-12-31','moneda'=>'MXN','prima_total'=>9999],
        ]);
        DB::table('cobranza_cuotas')->insert([
            ['id'=>1,'workspace_id'=>1,'poliza_id'=>1,'numero_cuota'=>1,'fecha_programada'=>'2026-10-28','monto'=>125.5,'estatus'=>'pendiente'],
            ['id'=>2,'workspace_id'=>2,'poliza_id'=>2,'numero_cuota'=>1,'fecha_programada'=>'2026-10-28','monto'=>9999,'estatus'=>'pendiente'],
            // Registro inconsistente: workspace actual con póliza ajena. No debe exportarse.
            ['id'=>3,'workspace_id'=>1,'poliza_id'=>2,'numero_cuota'=>2,'fecha_programada'=>'2026-10-28','monto'=>8888,'estatus'=>'pendiente'],
        ]);
        $queries=[];
        DB::listen(function ($event) use (&$queries) { $queries[]=$event->sql; });
        $filters = [
            'clientes'=>['q'=>'ana@example'],
            'agentes'=>['q'=>'Luis','status'=>'activo','empresa'=>'Aseguradora Uno'],
            'polizas'=>['q'=>'POL-A','estatus'=>'Vigente','hoy'=>'2026-10-28','tz_offset'=>360],
            'cobranza'=>['q'=>'Luis','estatus'=>'pendiente','desde'=>'2026-10-01','hasta'=>'2026-10-31'],
        ];
        foreach ($filters as $module=>$filter) {
            $this->postJson('/api/exports/'.$module.'/count', ['workspace_id'=>1,'filters'=>$filter])->assertOk()->assertJsonPath('total',1);
            foreach (['selected','filtered','all'] as $mode) {
                $sheet = $this->sheet($this->postJson('/api/exports/'.$module, ['workspace_id'=>1,'mode'=>$mode,'ids'=>[1],'filters'=>$filter]));
                $this->assertSame(2,substr_count($sheet,'<row '));
                $this->assertStringNotContainsString('NO-EXPORTAR',$sheet);
                $this->assertStringNotContainsString('POL-AJENA',$sheet);
                if (in_array($module,['polizas','cobranza'],true)) {
                    $this->assertStringContainsString('<v>125.5</v>',$sheet); $this->assertStringContainsString('USD',$sheet);
                    $this->assertStringNotContainsString('9999',$sheet);
                }
            }
            $this->postJson('/api/exports/'.$module,['workspace_id'=>1,'mode'=>'selected','ids'=>[2]])->assertUnprocessable();
        }
        foreach ($queries as $sql) $this->assertMatchesRegularExpression('/^(select|pragma)\b/i',ltrim($sql));
        DB::table('permission_role')->delete();
        foreach (array_keys($filters) as $module) $this->postJson('/api/exports/'.$module,['workspace_id'=>1,'mode'=>'all'])->assertForbidden();
    }
}
