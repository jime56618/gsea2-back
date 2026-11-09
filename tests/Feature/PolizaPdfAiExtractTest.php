<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWorkspaceNotLocked;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pruebas de integración del extractor de pólizas PDF + Google Gemini (Etapa 3B.1).
 * Verifica timeouts ampliados a 90s, soporte de PDFs con restricciones de permisos,
 * detección de contraseñas de apertura obligatorias y protección contra escrituras en BD.
 */
class PolizaPdfAiExtractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Verificación estricta de base de datos aislada en memoria
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->withoutMiddleware(EnsureWorkspaceNotLocked::class);

        Config::set('services.gemini.key', 'test-secret-gemini-api-key-999');
        Config::set('services.gemini.model', 'gemini-3.5-flash-lite');
        Config::set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        Config::set('services.gemini.timeout', 90);

        $this->createIsolatedSchema();
        $this->seedIsolatedFixtures();
    }

    private function createIsolatedSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('current_workspace_id')->nullable();
            $t->timestamps();
        });

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->string('slug');
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('group')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('role_id');
            $t->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->timestamps();
        });

        Schema::create('contratantes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('nombre');
            $t->string('rfc')->nullable();
            $t->string('telefono')->nullable();
            $t->string('email')->nullable();
            $t->string('direccion')->nullable();
            $t->timestamps();
        });

        Schema::create('aseguradoras', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->timestamps();
        });

        Schema::create('ramos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->timestamps();
        });

        Schema::create('subramos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ramo_id');
            $t->string('nombre');
            $t->timestamps();
        });

        Schema::create('agentes', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->string('apellido')->nullable();
            $t->string('cedula')->nullable();
            $t->timestamps();
        });

        Schema::create('agente_workspaces', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('agente_id');
            $t->string('status')->default('activo');
            $t->timestamps();
        });

        Schema::create('agente_workspace_aseguradora', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agente_workspace_id');
            $t->unsignedBigInteger('aseguradora_id');
            $t->string('clave_agente');
            $t->timestamps();
        });

        Schema::create('polizas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('numero_poliza');
            $t->timestamps();
        });

        Schema::create('poliza_vehiculos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('poliza_id');
            $t->timestamps();
        });

        Schema::create('poliza_coberturas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('poliza_id');
            $t->timestamps();
        });

        Schema::create('poliza_asegurados', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('poliza_id');
            $t->timestamps();
        });

        Schema::create('poliza_beneficiarios', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('poliza_id');
            $t->timestamps();
        });

        Schema::create('poliza_documentos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('poliza_id');
            $t->timestamps();
        });

        Schema::create('cobranza_cuotas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('poliza_id');
            $t->timestamps();
        });
    }

    private function seedIsolatedFixtures(): void
    {
        DB::table('workspaces')->insert([
            ['id' => 1, 'nombre' => 'Promotoria Uno', 'owner_id' => 1],
            ['id' => 2, 'nombre' => 'Promotoria Dos', 'owner_id' => 2],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Gestor Uno', 'email' => 'gestor1@gsea.test', 'current_workspace_id' => 1],
            ['id' => 2, 'name' => 'Solo Lectura', 'email' => 'lector1@gsea.test', 'current_workspace_id' => 1],
        ]);

        DB::table('roles')->insert([
            ['id' => 1, 'nombre' => 'Operador Pólizas', 'slug' => 'operador', 'workspace_id' => 1, 'is_system' => false],
            ['id' => 2, 'nombre' => 'Visor Pólizas', 'slug' => 'visor', 'workspace_id' => 1, 'is_system' => false],
        ]);

        $permManageId = DB::table('permissions')->insertGetId(['name' => 'polizas.manage']);
        $permViewId = DB::table('permissions')->insertGetId(['name' => 'polizas.view']);

        DB::table('permission_role')->insert([
            ['role_id' => 1, 'permission_id' => $permManageId],
            ['role_id' => 1, 'permission_id' => $permViewId],
            ['role_id' => 2, 'permission_id' => $permViewId],
        ]);

        DB::table('workspace_user')->insert([
            ['user_id' => 1, 'workspace_id' => 1, 'role_id' => 1],
            ['user_id' => 2, 'workspace_id' => 1, 'role_id' => 2],
        ]);

        // Catálogos globales
        DB::table('aseguradoras')->insert([
            ['id' => 10, 'nombre' => 'Quálitas Compañía de Seguros'],
            ['id' => 11, 'nombre' => 'GNP Seguros'],
        ]);

        DB::table('ramos')->insert([
            ['id' => 1, 'nombre' => 'VEHÍCULOS'],
            ['id' => 2, 'nombre' => 'ACCIDENTES Y ENFERMEDADES'],
        ]);

        DB::table('subramos')->insert([
            ['id' => 101, 'ramo_id' => 1, 'nombre' => 'Automóviles'],
            ['id' => 102, 'ramo_id' => 2, 'nombre' => 'Gastos Médicos Mayores'],
        ]);

        // Contratante en Workspace 1 y otro en Workspace 2
        DB::table('contratantes')->insert([
            [
                'id' => 50,
                'workspace_id' => 1,
                'nombre' => 'Carlos Ramírez López',
                'rfc' => 'RALC850101ABC',
                'telefono' => '5512345678',
            ],
            [
                'id' => 99,
                'workspace_id' => 2,
                'nombre' => 'Contratante Ajeno Promotoria Dos',
                'rfc' => 'AJEN900101XYZ',
                'telefono' => '5599999999',
            ],
        ]);

        // Agente en Workspace 1 con clave en Quálitas (id=10) y agente en Workspace 2
        DB::table('agentes')->insert([
            ['id' => 1, 'nombre' => 'Roberto', 'apellido' => 'Gómez'],
            ['id' => 2, 'nombre' => 'Agente', 'apellido' => 'Externo'],
        ]);

        DB::table('agente_workspaces')->insert([
            ['id' => 70, 'workspace_id' => 1, 'agente_id' => 1, 'status' => 'activo'],
            ['id' => 88, 'workspace_id' => 2, 'agente_id' => 2, 'status' => 'activo'],
        ]);

        DB::table('agente_workspace_aseguradora')->insert([
            ['agente_workspace_id' => 70, 'aseguradora_id' => 10, 'clave_agente' => 'CLV-QUA-001'],
            ['agente_workspace_id' => 88, 'aseguradora_id' => 10, 'clave_agente' => 'CLV-AJENA-999'],
        ]);
    }

    /**
     * Construye un archivo PDF 1.4 sintácticamente válido con el texto seleccionable indicado.
     */
    private function makeValidPdfFile(string $text, string $filename = 'poliza.pdf'): UploadedFile
    {
        $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 50 750 Td ({$escapedText}) Tj ET";
        $len = strlen($stream);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => "<< /Length {$len} >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return UploadedFile::fake()->createWithContent($filename, $pdf);
    }

    /**
     * Construye un PDF con restricciones de permisos de edición/impresión (/P -4) pero sin contraseña de usuario.
     */
    private function makePdfWithPermissionsOnly(string $text, string $filename = 'poliza_permisos.pdf'): UploadedFile
    {
        $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 50 750 Td ({$escapedText}) Tj ET";
        $len = strlen($stream);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => "<< /Length {$len} >>\nstream\n{$stream}\nendstream",
            6 => '<< /Filter /Standard /V 1 /R 2 /O (01234567890123456789012345678901) /U (01234567890123456789012345678901) /P -4 >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Encrypt 6 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return UploadedFile::fake()->createWithContent($filename, $pdf);
    }

    /**
     * Construye un archivo PDF protegido con contraseña de apertura obligatoria.
     */
    private function makePasswordProtectedPdfFile(): UploadedFile
    {
        $pdf = "%PDF-1.4\n" .
               "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n" .
               "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n" .
               "3 0 obj\n<< /Filter /Standard /V 2 /R 3 /O (01234567890123456789012345678901) /U (01234567890123456789012345678901) /P -4 >>\nendobj\n" .
               "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000109 00000 n \n" .
               "trailer\n<< /Size 4 /Root 1 0 R /Encrypt 3 0 R >>\nstartxref\n240\n%%EOF";

        return UploadedFile::fake()->createWithContent('protegida.pdf', $pdf);
    }

    private function getSampleGeminiJson(): array
    {
        return [
            'datos_generales' => [
                'numero_poliza' => 'QUA-2026-00991',
                'fecha_emision' => null,
                'inicio_vigencia' => '2026-05-01',
                'fin_vigencia' => '2027-05-01',
                'moneda' => 'MXN',
                'prima_neta' => 10000.00,
                'iva' => 1600.00,
                'prima_total' => 11600.00,
                'frecuencia_cobro' => 'mensual',
                'monto_cuota' => null,
                'telefono_notificacion' => null,
            ],
            'entidades_detectadas' => [
                'contratante' => [
                    'nombre' => 'Carlos Ramirez Lopez',
                    'rfc' => 'RALC850101ABC',
                    'telefono' => null,
                    'email' => null,
                    'direccion' => null,
                ],
                'aseguradora_nombre' => 'Qualitas Compania de Seguros',
                'ramo_nombre' => 'Vehiculos',
                'subramo_nombre' => 'Automoviles',
                'tipo_poliza' => 'auto',
                'agente' => [
                    'nombre' => 'Roberto Gomez',
                    'clave_agente' => 'CLV-QUA-001',
                ],
            ],
            'detalles' => [
                'vehiculo' => [
                    'marca' => 'NISSAN',
                    'modelo' => 'VERSA ADVANCE',
                    'anio' => 2024,
                    'serie' => '3N1CN7AD9KL000111',
                    'placas' => null,
                    'motor' => null,
                    'pasajeros' => 5,
                ],
                'gastos_medicos' => null,
                'viaje' => null,
                'asegurados' => [],
                'beneficiarios' => [],
                'coberturas' => [
                    [
                        'nombre' => 'Daños Materiales',
                        'clave' => 'DM',
                        'suma_asegurada' => null,
                        'suma_asegurada_texto' => 'Valor Comercial',
                        'deducible' => null,
                        'deducible_texto' => '5%',
                        'coaseguro_porcentaje' => null,
                        'prima' => 4500.00,
                        'moneda' => 'MXN',
                        'observaciones' => null,
                    ],
                ],
            ],
        ];
    }

    public function test_rechaza_acceso_sin_autenticacion_o_sin_permiso_polizas_manage(): void
    {
        Http::fake();

        $pdf = $this->makeValidPdfFile('Caratula de poliza de seguro numero 1234567890 vigente');

        // 1) Sin autenticar -> 401
        $unauth = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);
        $unauth->assertStatus(401);

        // 2) Usuario con solo polizas.view pero sin polizas.manage -> 403
        $this->actingAs(User::findOrFail(2));
        $forbidden = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);
        $forbidden->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_rechaza_pdf_invalido_o_vacio_sin_invocar_gemini(): void
    {
        Http::fake();
        $this->actingAs(User::findOrFail(1));

        $corruptFile = UploadedFile::fake()->createWithContent('corrupto.pdf', 'ESTE NO ES UN PDF VALIDO');

        $response = $this->postJson('/api/polizas/extract-pdf', [
            'pdf' => $corruptFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'invalid_pdf_header');

        Http::assertNothingSent();
    }

    public function test_rechaza_pdf_que_excede_limite_de_10mb_sin_invocar_gemini(): void
    {
        Http::fake();
        $this->actingAs(User::findOrFail(1));

        $oversizedFile = UploadedFile::fake()->create('muy_grande.pdf', 11 * 1024);

        $response = $this->postJson('/api/polizas/extract-pdf', [
            'pdf' => $oversizedFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'invalid_pdf_size');

        Http::assertNothingSent();
    }

    public function test_rechaza_pdf_protegido_con_contrasena_de_apertura_sin_invocar_gemini(): void
    {
        Http::fake();
        $this->actingAs(User::findOrFail(1));

        $protectedPdf = $this->makePasswordProtectedPdfFile();

        $response = $this->postJson('/api/polizas/extract-pdf', [
            'pdf' => $protectedPdf,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'pdf_password_protected');

        Http::assertNothingSent();

        // Verificar que no se escriba en BD ante fallo
        $this->assertSame(0, DB::table('polizas')->count());
    }

    public function test_pdf_con_restricciones_de_permisos_se_procesa_sin_contrasena_en_fase_a(): void
    {
        $this->actingAs(User::findOrFail(1));

        $geminiJson = $this->getSampleGeminiJson();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode($geminiJson, JSON_UNESCAPED_UNICODE)],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // PDF con restricciones de permisos de edición/impresión (/P -4) pero texto seleccionable
        $pdfWithPerms = $this->makePdfWithPermissionsOnly(
            'Caratula de Poliza GNP 987654321 con permisos de edicion restringidos Contratante Carlos Ramirez Lopez'
        );

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdfWithPerms]);

        $response->assertOk()
            ->assertJsonPath('source', 'text')
            ->assertJsonPath('form_data.numero_poliza', 'QUA-2026-00991');

        // Confirmar que se envió texto plano en Fase A sin bloquearse
        Http::assertSent(function ($request) {
            $data = $request->data();
            $parts = $data['contents'][0]['parts'] ?? [];
            return count($parts) === 1 && isset($parts[0]['text']);
        });
    }

    public function test_pdf_con_texto_suficiente_usa_fase_a_texto_plano_y_retorna_source_text(): void
    {
        $this->actingAs(User::findOrFail(1));

        $geminiJson = $this->getSampleGeminiJson();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode($geminiJson, JSON_UNESCAPED_UNICODE)],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $pdf = $this->makeValidPdfFile(
            'Caratula de Poliza Qualitas QUA-2026-00991 Contratante Carlos Ramirez Lopez RFC RALC850101ABC Vehiculo Nissan Versa 2024'
        );

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);

        $response->assertOk()
            ->assertJsonPath('source', 'text')
            ->assertJsonPath('workspace_id', 1)
            ->assertJsonPath('form_data.contratante_id', 50)
            ->assertJsonPath('form_data.aseguradora_id', 10)
            ->assertJsonPath('form_data.ramo_id', 1)
            ->assertJsonPath('form_data.subramo_id', 101)
            ->assertJsonPath('form_data.agente_id', 70)
            ->assertJsonPath('form_data.numero_poliza', 'QUA-2026-00991');

        // Confirmar que se envió texto plano en contents (Fase A)
        Http::assertSent(function ($request) {
            $data = $request->data();
            $parts = $data['contents'][0]['parts'] ?? [];
            return count($parts) === 1 && isset($parts[0]['text']) && !isset($parts[0]['inlineData']);
        });

        // Verificar que NO hubo escrituras en base de datos
        $this->assertSame(0, DB::table('polizas')->count());
    }

    public function test_pdf_sin_texto_suficiente_activa_fase_b_multimodal_con_inline_data_y_retorna_source_vision(): void
    {
        $this->actingAs(User::findOrFail(1));

        $geminiJson = $this->getSampleGeminiJson();

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode($geminiJson, JSON_UNESCAPED_UNICODE)],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // PDF sin texto seleccionable suficiente (< 25 caracteres)
        $emptyTextPdf = $this->makeValidPdfFile('   ');

        $response = $this->postJson('/api/polizas/extract-pdf', [
            'pdf' => $emptyTextPdf,
        ]);

        $response->assertOk()
            ->assertJsonPath('source', 'vision')
            ->assertJsonPath('form_data.numero_poliza', 'QUA-2026-00991');

        // Confirmar que se envió inlineData con application/pdf a Gemini (Fase B)
        Http::assertSent(function ($request) {
            $data = $request->data();
            $parts = $data['contents'][0]['parts'] ?? [];
            $hasInlineData = false;
            foreach ($parts as $part) {
                if (isset($part['inlineData']) && $part['inlineData']['mimeType'] === 'application/pdf') {
                    $hasInlineData = true;
                    $this->assertNotEmpty($part['inlineData']['data']);
                }
            }
            return $hasInlineData;
        });
    }

    public function test_error_429_de_cuota_en_gemini_retorna_429_y_no_escribe_en_bd(): void
    {
        $this->actingAs(User::findOrFail(1));

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted (e.g. check quota).',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        $pdf = $this->makeValidPdfFile('Caratula de Poliza Qualitas QUA-2026-00991 Contratante Carlos Ramirez Lopez');

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);

        $response->assertStatus(429)
            ->assertJsonPath('code', 'gemini_rate_limit');

        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('polizas')->count());
    }

    public function test_error_de_autenticacion_en_gemini_retorna_500_auth_error_sin_reintentar(): void
    {
        $this->actingAs(User::findOrFail(1));

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'error' => [
                    'code' => 401,
                    'message' => 'API_KEY_INVALID',
                    'status' => 'UNAUTHENTICATED',
                ],
            ], 401),
        ]);

        $pdf = $this->makeValidPdfFile('Caratula de Poliza Qualitas QUA-2026-00991 Contratante Carlos Ramirez Lopez');

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);

        $response->assertStatus(500)
            ->assertJsonPath('code', 'gemini_auth_error');

        Http::assertSentCount(1);
    }

    public function test_error_de_timeout_en_gemini_retorna_504_timeout_sin_escribir_en_bd(): void
    {
        $this->actingAs(User::findOrFail(1));

        $calledCount = 0;
        Http::fake([
            '*generativelanguage.googleapis.com*' => function () use (&$calledCount) {
                $calledCount++;
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $pdf = $this->makeValidPdfFile('Caratula de Poliza Qualitas QUA-2026-00991 Contratante Carlos Ramirez Lopez');

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);

        $response->assertStatus(504)
            ->assertJsonPath('code', 'gemini_timeout');

        $this->assertSame(1, $calledCount);
        $this->assertSame(0, DB::table('polizas')->count());
    }

    public function test_bloqueo_por_safety_de_gemini_retorna_422_safety_block(): void
    {
        $this->actingAs(User::findOrFail(1));

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'finishReason' => 'SAFETY',
                    ],
                ],
            ], 200),
        ]);

        $pdf = $this->makeValidPdfFile('Caratula de Poliza con contenido bloqueado por politicas de seguridad');

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'gemini_safety_block');
    }

    public function test_maneja_respuesta_invalida_o_error_de_gemini_sin_exponer_api_key(): void
    {
        $this->actingAs(User::findOrFail(1));
        $pdf = $this->makeValidPdfFile('Poliza de Seguro Automoviles Qualitas Numero POL-998877 Contratante Carlos Ramirez');

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'ESTO NO ES UN JSON VALIDO {{{'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'gemini_invalid_json');

        $this->assertStringNotContainsString('test-secret-gemini-api-key-999', $response->getContent());
    }

    public function test_aislamiento_entre_workspaces_nunca_asigna_contratante_ni_agente_de_otra_promotoria(): void
    {
        $this->actingAs(User::findOrFail(1)); // Usuario activo en Workspace #1

        $geminiJson = [
            'datos_generales' => [
                'numero_poliza' => 'POL-WS2-TEST',
                'inicio_vigencia' => '2026-01-01',
                'fin_vigencia' => '2027-01-01',
            ],
            'entidades_detectadas' => [
                'contratante' => [
                    'nombre' => 'Contratante Ajeno Promotoria Dos',
                    'rfc' => 'AJEN900101XYZ',
                ],
                'aseguradora_nombre' => 'Quálitas Compañía de Seguros',
                'ramo_nombre' => 'VEHÍCULOS',
                'subramo_nombre' => 'Automóviles',
                'tipo_poliza' => 'auto',
                'agente' => [
                    'nombre' => 'Agente Externo',
                    'clave_agente' => 'CLV-AJENA-999',
                ],
            ],
            'detalles' => [],
        ];

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode($geminiJson, JSON_UNESCAPED_UNICODE)],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $pdf = $this->makeValidPdfFile('Poliza de prueba aislamiento multi-workspace con contratante de otra promotoria');

        $response = $this->postJson('/api/polizas/extract-pdf', ['pdf' => $pdf]);

        $response->assertOk()
            ->assertJsonPath('workspace_id', 1)
            ->assertJsonPath('form_data.contratante_id', null)
            ->assertJsonPath('form_data.agente_id', null)
            ->assertJsonPath('matches.contratante.id', null)
            ->assertJsonPath('matches.agente.id', null);

        $warnings = $response->json('warnings');
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);
    }
}
