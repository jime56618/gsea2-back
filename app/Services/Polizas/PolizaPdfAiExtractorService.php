<?php

namespace App\Services\Polizas;

use App\Models\AgenteWorkspace;
use App\Models\Aseguradora;
use App\Models\Contratante;
use App\Models\Ramo;
use App\Models\Subramo;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

class PolizaPdfAiExtractorService
{
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    private const MIN_EXTRACTABLE_CHARS = 25;
    private const MAX_PROMPT_TEXT_CHARS = 35000;

    private int $lastGeminiAttempts = 1;

    /**
     * Extrae el texto de un PDF de póliza y utiliza Google Gemini para devolver una estructura
     * compatible con el formulario y contratos de Pólizas de GSEA, sin escribir en la base de datos.
     *
     * @return array<string, mixed>
     */
    public function extract(UploadedFile $file, int $workspaceId): array
    {
        $startTime = microtime(true);
        $fileSizeBytes = (int) $file->getSize();

        $this->validatePdfFile($file);

        $realPath = (string) $file->getRealPath();

        // 1. Intentar Fase A: Extracción de texto plano con Smalot\PdfParser\Parser (con ignoreEncryption=true)
        $textExtractionFailed = false;
        $isPasswordProtected = false;
        $cleanText = '';

        try {
            $rawText = $this->extractTextFromPdf($file);
            $cleanText = $this->sanitizeExtractedText($rawText);

            if ($this->countAlphaNumericChars($cleanText) < self::MIN_EXTRACTABLE_CHARS) {
                // Escaneado o sin texto suficiente
                $textExtractionFailed = true;
            }
        } catch (Throwable $e) {
            $textExtractionFailed = true;
            if ($this->isPdfUserPasswordRequiredException($e, $realPath)) {
                $isPasswordProtected = true;
            }
        }

        // Si se confirmó que el documento requiere contraseña de apertura para ser leído:
        if ($isPasswordProtected) {
            $this->logTelemetry('parser', $startTime, $fileSizeBytes, 422, 'pdf_password_protected');
            throw new HttpResponseException(response()->json([
                'message' => 'La póliza está protegida con contraseña. Por favor, remueve la contraseña del PDF antes de subirlo.',
                'code' => 'pdf_password_protected',
            ], 422));
        }

        // Si la extracción de texto tuvo éxito (incluyendo PDFs con restricciones de permisos):
        if (! $textExtractionFailed) {
            try {
                $aiRaw = $this->callGeminiStructuredExtraction($cleanText);
                $result = $this->normalizeAndResolve($aiRaw, $workspaceId, 'text');

                $this->logTelemetry('text', $startTime, $fileSizeBytes, 200, 'success', $this->lastGeminiAttempts);
                return $result;
            } catch (HttpResponseException $e) {
                $body = json_decode((string) $e->getResponse()->getContent(), true) ?: [];
                $code = $body['code'] ?? '';
                $statusCode = $e->getResponse()->getStatusCode();

                // Si es un error de cuenta/servicio (rate limit 429, auth, timeout, unavailable),
                // NO intentar fallback visual (no es un problema de lectura del documento).
                if (in_array($code, ['gemini_rate_limit', 'gemini_not_configured', 'gemini_auth_error', 'gemini_timeout', 'gemini_connection_error', 'gemini_service_unavailable'], true)) {
                    $this->logTelemetry('text', $startTime, $fileSizeBytes, $statusCode, $code, $this->lastGeminiAttempts);
                    throw $e;
                }
                // Si fue un fallo de interpretación (ej. gemini_empty_response o gemini_invalid_json),
                // permitimos fallback a Fase B (visión multimodal).
            }
        }

        // 2. Fase B: Respaldo de Lectura Visual Multimodal con Gemini (inlineData)
        try {
            $aiRawVision = $this->callGeminiMultimodalExtraction($file);
            $result = $this->normalizeAndResolve($aiRawVision, $workspaceId, 'vision');

            $this->logTelemetry('vision', $startTime, $fileSizeBytes, 200, 'success', $this->lastGeminiAttempts);
            return $result;
        } catch (HttpResponseException $e) {
            $body = json_decode((string) $e->getResponse()->getContent(), true) ?: [];
            $code = $body['code'] ?? 'gemini_error';
            $statusCode = $e->getResponse()->getStatusCode();

            $this->logTelemetry('vision', $startTime, $fileSizeBytes, $statusCode, $code, $this->lastGeminiAttempts);
            throw $e;
        }
    }

    /**
     * Valida que el archivo recibido sea un PDF íntegro dentro del tamaño permitido.
     */
    private function validatePdfFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new HttpResponseException(response()->json([
                'message' => 'El archivo PDF subido no es válido.',
                'code' => 'invalid_pdf',
            ], 422));
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_FILE_SIZE_BYTES) {
            throw new HttpResponseException(response()->json([
                'message' => 'El archivo PDF está vacío o excede el tamaño máximo permitido de 10 MB.',
                'code' => 'invalid_pdf_size',
            ], 422));
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        if ($ext !== 'pdf' && $mime !== 'application/pdf') {
            throw new HttpResponseException(response()->json([
                'message' => 'El archivo debe ser un documento PDF válido (.pdf).',
                'code' => 'invalid_pdf_type',
            ], 422));
        }

        $realPath = $file->getRealPath();
        if (! $realPath || ! is_readable($realPath)) {
            throw new HttpResponseException(response()->json([
                'message' => 'No fue posible leer el archivo PDF temporal.',
                'code' => 'invalid_pdf',
            ], 422));
        }

        $handle = @fopen($realPath, 'rb');
        $header = $handle ? (string) fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($header !== '%PDF-') {
            throw new HttpResponseException(response()->json([
                'message' => 'El archivo proporcionado no tiene un encabezado PDF válido.',
                'code' => 'invalid_pdf_header',
            ], 422));
        }
    }

    /**
     * Extrae el texto plano del PDF utilizando Smalot\PdfParser\Parser.
     */
    private function extractTextFromPdf(UploadedFile $file): string
    {
        $config = new Config();
        $config->setIgnoreEncryption(true);
        $parser = new Parser([], $config);
        $pdf = $parser->parseFile((string) $file->getRealPath());

        return (string) $pdf->getText();
    }

    /**
     * Determina si la excepción ocurrida en PdfParser se debe a un PDF con contraseña de apertura.
     * Si el archivo contiene /Encrypt y los objetos están protegidos, requiere contraseña de apertura.
     */
    private function isPdfUserPasswordRequiredException(Throwable $e, string $filePath): bool
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'possible secured file') || str_contains($msg, 'secured pdf file')) {
            return true;
        }

        if (str_contains($msg, 'password') || str_contains($msg, 'encrypted') || str_contains($msg, 'encrypt')) {
            return true;
        }

        return $this->hasActiveEncryptionDictionary($filePath);
    }

    /**
     * Inspecciona si el archivo contiene un diccionario de cifrado activo (/Encrypt).
     */
    private function hasActiveEncryptionDictionary(string $filePath): bool
    {
        $handle = @fopen($filePath, 'rb');
        if (! $handle) {
            return false;
        }

        $buffer = (string) fread($handle, 2 * 1024 * 1024);
        if (fseek($handle, -65536, SEEK_END) !== -1) {
            $buffer .= (string) fread($handle, 65536);
        }
        fclose($handle);

        return (bool) preg_match('#/Encrypt\s+(\d+\s+\d+\s+R|<<)#i', $buffer);
    }

    /**
     * Registra métricas de telemetría de rendimiento y diagnóstico sin información confidencial.
     * Nunca incluye nombres, RFC, números de póliza, contenido de texto, Base64 ni claves de API.
     */
    private function logTelemetry(string $stage, float $startTime, int $fileSizeBytes, int $statusCode, string $resultCode, int $attempts = 1): void
    {
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $fileSizeKb = round($fileSizeBytes / 1024, 1);

        $context = [
            'stage' => $stage,
            'duration_ms' => $durationMs,
            'file_size_kb' => $fileSizeKb,
            'status_code' => $statusCode,
            'result_code' => $resultCode,
            'attempts' => $attempts,
        ];

        if ($statusCode >= 400) {
            Log::warning('Poliza PDF AI extraction telemetry: error', $context);
        } else {
            Log::info('Poliza PDF AI extraction telemetry: success', $context);
        }
    }

    private function sanitizeExtractedText(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $clean = preg_replace('/[ \t]+/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\R{3,}/u', "\n\n", $clean) ?? $clean;
        $clean = trim($clean);

        if (mb_strlen($clean, 'UTF-8') > self::MAX_PROMPT_TEXT_CHARS) {
            $clean = mb_substr($clean, 0, self::MAX_PROMPT_TEXT_CHARS, 'UTF-8');
        }

        return $clean;
    }

    private function countAlphaNumericChars(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]/u', $text, $matches);

        return count($matches[0]);
    }

    /**
     * Envía el texto extraído a Google Gemini (`generateContent`) exigiendo respuesta JSON.
     * No registra contenido sensible ni claves en logs.
     *
     * @return array<string, mixed>
     */
    private function callGeminiStructuredExtraction(string $documentText): array
    {
        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $this->buildSystemInstruction()],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => "Extrae los datos de la siguiente póliza de seguros y devuelve únicamente el objeto JSON solicitado:\n\n--- INICIO DE DOCUMENTO ---\n"
                                . $documentText
                                . "\n--- FIN DE DOCUMENTO ---",
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'responseMimeType' => 'application/json',
            ],
        ];

        return $this->executeGeminiRequest($payload);
    }

    /**
     * Envía el archivo PDF directamente como inlineData (application/pdf) mediante visión multimodal de Gemini.
     * No registra el contenido binario ni Base64 en logs.
     *
     * @return array<string, mixed>
     */
    private function callGeminiMultimodalExtraction(UploadedFile $file): array
    {
        $realPath = (string) $file->getRealPath();
        $size = (int) $file->getSize();

        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw new HttpResponseException(response()->json([
                'message' => 'El archivo PDF excede el tamaño máximo permitido de 10 MB.',
                'code' => 'invalid_pdf_size',
            ], 422));
        }

        $pdfBytes = @file_get_contents($realPath);
        if ($pdfBytes === false || $pdfBytes === '') {
            throw new HttpResponseException(response()->json([
                'message' => 'No fue posible leer el contenido del archivo PDF para el análisis visual.',
                'code' => 'invalid_pdf',
            ], 422));
        }

        $base64Data = base64_encode($pdfBytes);

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $this->buildSystemInstruction()],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => 'application/pdf',
                                'data' => $base64Data,
                            ],
                        ],
                        [
                            'text' => "Analiza visualmente la carátula de póliza de seguros adjunta en el documento PDF. "
                                . "Extrae de manera literal y estructurada todos los datos legibles (incluyendo sellos, tablas de coberturas, vigencias y datos del bien asegurado) "
                                . "y devuelve EXCLUSIVAMENTE el objeto JSON solicitado conforme a la estructura de las instrucciones del sistema.",
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'responseMimeType' => 'application/json',
            ],
        ];

        return $this->executeGeminiRequest($payload);
    }

    /**
     * Ejecuta la solicitud HTTP hacia la API de Google Gemini con control granular de errores.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function executeGeminiRequest(array $payload): array
    {
        $apiKey = trim((string) config('services.gemini.key', ''));
        $model = trim((string) config('services.gemini.model', 'gemini-3.5-flash-lite')) ?: 'gemini-3.5-flash-lite';
        $baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(5, (int) config('services.gemini.timeout', 90));

        if ($apiKey === '') {
            throw new HttpResponseException(response()->json([
                'message' => 'El servicio de análisis con IA no está configurado en el servidor.',
                'code' => 'gemini_not_configured',
            ], 503));
        }

        $endpoint = sprintf('%s/models/%s:generateContent', $baseUrl, rawurlencode($model));

        $maxAttempts = 2;
        $attempt = 0;
        $response = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $this->lastGeminiAttempts = $attempt;

            try {
                $response = Http::connectTimeout(30)
                    ->timeout($timeout)
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ])
                    ->post($endpoint, $payload);
            } catch (ConnectionException $e) {
                $previous = $e->getPrevious();

                $handlerContext = $previous instanceof \GuzzleHttp\Exception\ConnectException
                    ? $previous->getHandlerContext()
                    : [];

                $curlErrno = isset($handlerContext['errno']) ? (int) $handlerContext['errno'] : null;

                Log::warning('Gemini connection diagnostic', [
                    'attempt' => $attempt,
                    'exception_type' => get_class($e),
                    'previous_exception_type' => $previous ? get_class($previous) : null,
                    'curl_errno' => $curlErrno,
                    'http_code' => $handlerContext['http_code'] ?? null,
                    'namelookup_time' => $handlerContext['namelookup_time'] ?? null,
                    'connect_time' => $handlerContext['connect_time'] ?? null,
                    'appconnect_time' => $handlerContext['appconnect_time'] ?? null,
                    'total_time' => $handlerContext['total_time'] ?? null,
                ]);

                // Reintentar solo si cURL no pudo resolver el dominio (errno 6).
                if ($curlErrno === 6 && $attempt < $maxAttempts) {
                    Log::info('Gemini DNS resolution failed, retrying in 1s', [
                        'attempt' => $attempt,
                        'curl_errno' => $curlErrno,
                    ]);
                    usleep(1000000);
                    continue;
                }

                // Mantener el manejo anterior para los demás errores de conexión.
                throw new HttpResponseException(response()->json([
                    'message' => $curlErrno === 6
                        ? 'No fue posible resolver temporalmente el servidor de IA. Inténtalo nuevamente.'
                        : 'Se agotó el tiempo de espera al comunicarse con el servicio de IA.',
                    'code' => $curlErrno === 6 ? 'gemini_connection_error' : 'gemini_timeout',
                ], $curlErrno === 6 ? 502 : 504));
            }

            // Si es 503 y aún nos queda un reintento disponible, esperar 1 segundo y reintentar
            if ($response->status() === 503 && $attempt < $maxAttempts) {
                Log::info('Poliza PDF AI extraction: temporary 503 spike, retrying in 1s', [
                    'attempt' => $attempt,
                    'status_code' => 503,
                ]);
                usleep(1000000); // 1 segundo
                continue;
            }

            // Para cualquier otro código de respuesta (exitoso, 429, 401, 400, etc.), no reintentar
            break;
        }

        if (! $response || ! $response->successful()) {
            $status = $response->status();

            if ($status === 429) {
                throw new HttpResponseException(response()->json([
                    'message' => 'El servicio de inteligencia artificial ha alcanzado su límite de consultas por minuto. Espera un momento y vuelve a intentarlo.',
                    'code' => 'gemini_rate_limit',
                ], 429));
            }

            if ($status === 401 || $status === 403) {
                throw new HttpResponseException(response()->json([
                    'message' => 'La clave o credenciales del servicio de IA no son válidas o carecen de permisos en el servidor.',
                    'code' => 'gemini_auth_error',
                ], 500));
            }

            if ($status === 400) {
                $errBody = (string) $response->json('error.message');
                if (stripos($errBody, 'password') !== false || stripos($errBody, 'encrypted') !== false) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'La póliza está protegida con contraseña. Por favor, remueve la contraseña del PDF antes de subirlo.',
                        'code' => 'pdf_password_protected',
                    ], 422));
                }

                throw new HttpResponseException(response()->json([
                    'message' => 'El documento no pudo ser procesado por el servicio de IA debido a un formato no soportado o restricciones de contenido.',
                    'code' => 'gemini_invalid_argument',
                ], 422));
            }

            if (in_array($status, [500, 502, 503, 504], true)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'El servicio de Google Gemini no se encuentra disponible temporalmente. Inténtalo más tarde.',
                    'code' => 'gemini_service_unavailable',
                ], 502));
            }

            throw new HttpResponseException(response()->json([
                'message' => 'El servicio de inteligencia artificial rechazó o no pudo completar el análisis del documento.',
                'code' => 'gemini_http_error',
            ], 502));
        }

        $body = $response->json();
        $candidate = data_get($body, 'candidates.0');
        $finishReason = data_get($candidate, 'finishReason');

        if ($finishReason === 'SAFETY') {
            throw new HttpResponseException(response()->json([
                'message' => 'El documento fue bloqueado por las políticas de seguridad del servicio de IA.',
                'code' => 'gemini_safety_block',
            ], 422));
        }

        $rawJsonText = data_get($candidate, 'content.parts.0.text');

        if (! is_string($rawJsonText) || trim($rawJsonText) === '') {
            throw new HttpResponseException(response()->json([
                'message' => 'El servicio de IA no devolvió contenido estructurado para esta póliza.',
                'code' => 'gemini_empty_response',
            ], 422));
        }

        $cleanedJson = $this->stripMarkdownCodeFences($rawJsonText);
        $decoded = json_decode($cleanedJson, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpResponseException(response()->json([
                'message' => 'La respuesta del análisis de IA no tuvo un formato JSON válido.',
                'code' => 'gemini_invalid_json',
            ], 422));
        }

        return $decoded;
    }

    private function stripMarkdownCodeFences(string $text): string
    {
        $trimmed = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $m)) {
            return trim($m[1]);
        }

        return $trimmed;
    }

    private function buildSystemInstruction(): string
    {
        return <<<'PROMPT'
Eres un extractor experto de carátulas de pólizas de seguros en México para la plataforma CRM GSEA.
Tu única tarea es extraer de manera literal y exacta la información presente en el texto del documento PDF y devolver EXCLUSIVAMENTE un objeto JSON válido con la siguiente estructura exacta.

REGLAS ESTRICTAS OBLIGATORIAS:
1. NO inventes, NO adivines, NO completes ni asumas fechas, importes, nombres, RFC, CURP, placas, series, coberturas ni identificadores que no aparezcan claramente en el texto.
2. Cuando un dato no aparezca explícitamente en el documento, devuelve `null` en ese campo (o `[]` para listas vacías).
3. Todas las fechas deben formatearse como `YYYY-MM-DD`. Si una fecha es ambigua o incompleta, devuelve `null`.
4. Todos los importes numéricos (`prima_neta`, `iva`, `prima_total`, `monto_cuota`, `suma_asegurada`, `deducible`, `coaseguro_porcentaje`, `prima`, `tope_coaseguro`, `porcentaje`) deben ser números decimales sin símbolo de moneda ni comas, o `null`.
5. `frecuencia_cobro` solo puede ser uno de: `"unico"`, `"mensual"`, `"trimestral"`, `"semestral"`, `"anual"` o `null`.
6. `tipo_poliza` solo puede ser uno de: `"auto"`, `"moto"`, `"medicos"`, `"vida"`, `"danos"`, `"accidentes"`, `"viaje"` o `null`.
7. No devuelvas ningún texto fuera del JSON.

ESTRUCTURA JSON REQUERIDA:
{
  "datos_generales": {
    "numero_poliza": string|null,
    "fecha_emision": "YYYY-MM-DD"|null,
    "inicio_vigencia": "YYYY-MM-DD"|null,
    "fin_vigencia": "YYYY-MM-DD"|null,
    "moneda": "MXN"|"USD"|"EUR"|"UDI"|null,
    "prima_neta": number|null,
    "iva": number|null,
    "prima_total": number|null,
    "frecuencia_cobro": "unico"|"mensual"|"trimestral"|"semestral"|"anual"|null,
    "monto_cuota": number|null,
    "telefono_notificacion": string|null
  },
  "entidades_detectadas": {
    "contratante": {
      "nombre": string|null,
      "rfc": string|null,
      "telefono": string|null,
      "email": string|null,
      "direccion": string|null
    },
    "aseguradora_nombre": string|null,
    "ramo_nombre": string|null,
    "subramo_nombre": string|null,
    "tipo_poliza": "auto"|"moto"|"medicos"|"vida"|"danos"|"accidentes"|"viaje"|null,
    "agente": {
      "nombre": string|null,
      "clave_agente": string|null
    }
  },
  "detalles": {
    "vehiculo": {
      "marca": string|null,
      "modelo": string|null,
      "anio": integer|null,
      "serie": string|null,
      "placas": string|null,
      "motor": string|null,
      "pasajeros": integer|null
    },
    "gastos_medicos": {
      "nombre_plan": string|null,
      "nivel_hospitalario": string|null,
      "suma_asegurada": number|null,
      "suma_asegurada_texto": string|null,
      "deducible": number|null,
      "deducible_texto": string|null,
      "coaseguro_porcentaje": number|null,
      "tope_coaseguro": number|null,
      "observaciones": string|null
    },
    "viaje": {
      "nombre_plan": string|null,
      "origen": string|null,
      "destino": string|null,
      "fecha_inicio_viaje": "YYYY-MM-DD"|null,
      "fecha_fin_viaje": "YYYY-MM-DD"|null,
      "observaciones": string|null
    },
    "asegurados": [
      {
        "nombre": string|null,
        "fecha_nacimiento": "YYYY-MM-DD"|null,
        "sexo": string|null,
        "parentesco": string|null,
        "rfc": string|null,
        "curp": string|null,
        "antiguedad": "YYYY-MM-DD"|null,
        "titular": boolean|null
      }
    ],
    "beneficiarios": [
      {
        "nombre": string|null,
        "parentesco": string|null,
        "porcentaje": number|null,
        "fecha_nacimiento": "YYYY-MM-DD"|null
      }
    ],
    "coberturas": [
      {
        "nombre": string|null,
        "clave": string|null,
        "suma_asegurada": number|null,
        "suma_asegurada_texto": string|null,
        "deducible": number|null,
        "deducible_texto": string|null,
        "coaseguro_porcentaje": number|null,
        "prima": number|null,
        "moneda": string|null,
        "observaciones": string|null
      }
    ]
  }
}
PROMPT;
    }

    /**
     * Normaliza la respuesta de Gemini y resuelve los IDs de catálogos exclusivamente desde Laravel
     * respetando el aislamiento del workspace activo.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeAndResolve(array $raw, int $workspaceId, string $source = 'text'): array
    {
        $warnings = [];

        $gen = is_array($raw['datos_generales'] ?? null) ? $raw['datos_generales'] : $raw;
        $ent = is_array($raw['entidades_detectadas'] ?? null) ? $raw['entidades_detectadas'] : [];
        $det = is_array($raw['detalles'] ?? null) ? $raw['detalles'] : [];

        // 1. Resolver catálogos de manera segura en Laravel (nunca confiar en IDs externos)
        $contratanteRaw = is_array($ent['contratante'] ?? null) ? $ent['contratante'] : [];
        $contratanteDetected = [
            'nombre' => $this->cleanString($contratanteRaw['nombre'] ?? null, 255),
            'rfc' => $this->cleanUpperCode($contratanteRaw['rfc'] ?? null, 20),
            'telefono' => $this->cleanString($contratanteRaw['telefono'] ?? null, 30),
            'email' => $this->cleanString($contratanteRaw['email'] ?? null, 255),
            'direccion' => $this->cleanString($contratanteRaw['direccion'] ?? null, 500),
        ];

        $contratanteMatch = $this->resolveContratanteInWorkspace($workspaceId, $contratanteDetected, $warnings);

        $aseguradoraNombreDetectado = $this->cleanString($ent['aseguradora_nombre'] ?? null, 255);
        $aseguradoraMatch = $this->resolveAseguradora($aseguradoraNombreDetectado, $warnings);

        $ramoNombreDetectado = $this->cleanString($ent['ramo_nombre'] ?? null, 255);
        $subramoNombreDetectado = $this->cleanString($ent['subramo_nombre'] ?? null, 255);
        $tipoPolizaDetectado = $this->normalizeTipoPoliza($ent['tipo_poliza'] ?? null);

        $ramoSubramoMatch = $this->resolveRamoAndSubramo(
            $ramoNombreDetectado,
            $subramoNombreDetectado,
            $tipoPolizaDetectado,
            $warnings
        );

        $agenteRaw = is_array($ent['agente'] ?? null) ? $ent['agente'] : [];
        $agenteDetected = [
            'nombre' => $this->cleanString($agenteRaw['nombre'] ?? null, 255),
            'clave_agente' => $this->cleanString($agenteRaw['clave_agente'] ?? null, 100),
        ];

        $agenteMatch = $this->resolveAgenteInWorkspace(
            $workspaceId,
            $aseguradoraMatch['id'],
            $agenteDetected,
            $warnings
        );

        // 2. Normalizar campos generales del formulario
        $numeroPoliza = $this->cleanString($gen['numero_poliza'] ?? null, 255);
        $fechaEmision = $this->normalizeDate($gen['fecha_emision'] ?? null);
        $inicioVigencia = $this->normalizeDate($gen['inicio_vigencia'] ?? null);
        $finVigencia = $this->normalizeDate($gen['fin_vigencia'] ?? null);

        if ($inicioVigencia && $finVigencia && $finVigencia < $inicioVigencia) {
            $warnings[] = 'La fecha de fin de vigencia detectada es anterior al inicio de vigencia; se omitió para revisión humana.';
            $finVigencia = null;
        }

        $moneda = $this->normalizeMoneda($gen['moneda'] ?? null);
        $primaNeta = $this->normalizeMoney($gen['prima_neta'] ?? null);
        $iva = $this->normalizeMoney($gen['iva'] ?? null);
        $primaTotal = $this->normalizeMoney($gen['prima_total'] ?? null);
        $frecuenciaCobro = $this->normalizeFrecuenciaCobro($gen['frecuencia_cobro'] ?? null);
        $montoCuota = $this->normalizeMoney($gen['monto_cuota'] ?? null);
        $telefonoNotificacion = $this->cleanString(
            $gen['telefono_notificacion'] ?? $contratanteDetected['telefono'] ?? null,
            30
        );

        if ($numeroPoliza === null) {
            $warnings[] = 'No se detectó claramente el número de póliza en el documento.';
        }
        if ($inicioVigencia === null || $finVigencia === null) {
            $warnings[] = 'No se detectó el periodo completo de vigencia (inicio y/o fin).';
        }

        // 3. Normalizar detalles especializados compatibles con PolizaDetallesService y SeguimientoPolizas.jsx
        $vehiculo = $this->normalizeVehiculo(is_array($det['vehiculo'] ?? null) ? $det['vehiculo'] : []);
        $gastosMedicos = $this->normalizeGastosMedicos(is_array($det['gastos_medicos'] ?? null) ? $det['gastos_medicos'] : []);
        $viaje = $this->normalizeViaje(is_array($det['viaje'] ?? null) ? $det['viaje'] : []);
        $asegurados = $this->normalizeAsegurados(is_array($det['asegurados'] ?? null) ? $det['asegurados'] : []);
        $beneficiarios = $this->normalizeBeneficiarios(is_array($det['beneficiarios'] ?? null) ? $det['beneficiarios'] : []);
        $coberturas = $this->normalizeCoberturas(is_array($det['coberturas'] ?? null) ? $det['coberturas'] : []);

        return [
            'success' => true,
            'source' => $source,
            'workspace_id' => $workspaceId,
            'form_data' => [
                'contratante_id' => $contratanteMatch['id'],
                'aseguradora_id' => $aseguradoraMatch['id'],
                'ramo_id' => $ramoSubramoMatch['ramo_id'],
                'subramo_id' => $ramoSubramoMatch['subramo_id'],
                'agente_id' => $agenteMatch['id'],
                'numero_poliza' => $numeroPoliza,
                'fecha_emision' => $fechaEmision,
                'inicio_vigencia' => $inicioVigencia,
                'fin_vigencia' => $finVigencia,
                'prima_neta' => $primaNeta,
                'iva' => $iva,
                'prima_total' => $primaTotal,
                'moneda' => $moneda,
                'frecuencia_cobro' => $frecuenciaCobro,
                'monto_cuota' => $montoCuota,
                'telefono_notificacion' => $telefonoNotificacion,
            ],
            'tipo_poliza' => $ramoSubramoMatch['tipo_poliza'] ?? $tipoPolizaDetectado,
            'detalles' => [
                'vehiculo' => $vehiculo,
                'gastos_medicos' => $gastosMedicos,
                'viaje' => $viaje,
                'asegurados' => $asegurados,
                'beneficiarios' => $beneficiarios,
                'coberturas' => $coberturas,
            ],
            'detected' => [
                'contratante' => $contratanteDetected,
                'aseguradora_nombre' => $aseguradoraNombreDetectado,
                'ramo_nombre' => $ramoNombreDetectado,
                'subramo_nombre' => $subramoNombreDetectado,
                'agente' => $agenteDetected,
            ],
            'matches' => [
                'contratante' => $contratanteMatch,
                'aseguradora' => $aseguradoraMatch,
                'ramo' => [
                    'id' => $ramoSubramoMatch['ramo_id'],
                    'nombre' => $ramoSubramoMatch['ramo_nombre'],
                ],
                'subramo' => [
                    'id' => $ramoSubramoMatch['subramo_id'],
                    'nombre' => $ramoSubramoMatch['subramo_nombre'],
                ],
                'agente' => $agenteMatch,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Busca coincidencia de contratante EXCLUSIVAMENTE dentro del workspace activo.
     *
     * @param  array<string, string|null>  $detected
     * @param  list<string>  $warnings
     * @return array{id: int|null, nombre: string|null, rfc: string|null, matched_by: string|null}
     */
    private function resolveContratanteInWorkspace(int $workspaceId, array $detected, array &$warnings): array
    {
        $rfcDetectado = $this->normalizeIdentifier($detected['rfc'] ?? null);
        $nombreDetectado = $this->normalizeSearchText($detected['nombre'] ?? null);

        if ($rfcDetectado === '' && $nombreDetectado === '') {
            $warnings[] = 'No se detectó el nombre o RFC del contratante en el PDF. Selecciónalo manualmente.';

            return ['id' => null, 'nombre' => null, 'rfc' => null, 'matched_by' => null];
        }

        $contratantes = Contratante::query()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'workspace_id', 'nombre', 'rfc']);

        // 1) Coincidencia por RFC exacto dentro del workspace
        if ($rfcDetectado !== '' && strlen($rfcDetectado) >= 10) {
            $byRfc = $contratantes->filter(function (Contratante $c) use ($rfcDetectado) {
                return $this->normalizeIdentifier($c->rfc) === $rfcDetectado;
            })->values();

            if ($byRfc->count() === 1) {
                $match = $byRfc->first();

                return [
                    'id' => (int) $match->id,
                    'nombre' => $match->nombre,
                    'rfc' => $match->rfc,
                    'matched_by' => 'rfc',
                ];
            }

            if ($byRfc->count() > 1) {
                $warnings[] = sprintf(
                    'Se encontraron múltiples contratantes en tu promotoría con el RFC «%s». Selecciona el contratante manualmente.',
                    $detected['rfc']
                );

                return ['id' => null, 'nombre' => null, 'rfc' => null, 'matched_by' => null];
            }
        }

        // 2) Coincidencia por nombre completo normalizado dentro del workspace
        if ($nombreDetectado !== '') {
            $byName = $contratantes->filter(function (Contratante $c) use ($nombreDetectado) {
                return $this->normalizeSearchText($c->nombre) === $nombreDetectado;
            })->values();

            if ($byName->count() === 1) {
                $match = $byName->first();

                return [
                    'id' => (int) $match->id,
                    'nombre' => $match->nombre,
                    'rfc' => $match->rfc,
                    'matched_by' => 'nombre',
                ];
            }

            if ($byName->count() > 1) {
                $warnings[] = sprintf(
                    'Existen varios contratantes registrados con el nombre «%s» en tu promotoría. Selecciónalo manualmente.',
                    $detected['nombre']
                );

                return ['id' => null, 'nombre' => null, 'rfc' => null, 'matched_by' => null];
            }
        }

        $label = $detected['nombre'] ?: $detected['rfc'];
        $warnings[] = sprintf(
            'El contratante detectado («%s») no está registrado o no coincide exactamente en tu promotoría. Selecciónalo o dalo de alta.',
            $label
        );

        return ['id' => null, 'nombre' => null, 'rfc' => null, 'matched_by' => null];
    }

    /**
     * Resuelve la aseguradora contra el catálogo registrado en Laravel.
     *
     * @param  list<string>  $warnings
     * @return array{id: int|null, nombre: string|null}
     */
    private function resolveAseguradora(?string $detectedName, array &$warnings): array
    {
        $normDetected = $this->normalizeSearchText($detectedName);
        if ($normDetected === '') {
            $warnings[] = 'No se identificó el nombre de la aseguradora en el documento.';

            return ['id' => null, 'nombre' => null];
        }

        $aseguradoras = Aseguradora::query()->get(['id', 'nombre']);

        // 1) Coincidencia exacta normalizada
        $exact = $aseguradoras->filter(fn (Aseguradora $a) => $this->normalizeSearchText($a->nombre) === $normDetected)->values();
        if ($exact->count() === 1) {
            $m = $exact->first();

            return ['id' => (int) $m->id, 'nombre' => $m->nombre];
        }

        // 2) Alias comerciales comunes en México -> palabra clave normalizada
        $aliases = [
            'gnp' => ['gnp', 'grupo nacional provincial'],
            'qualitas' => ['qualitas', 'qualitas compania de seguros'],
            'axa' => ['axa', 'axa seguros'],
            'chubb' => ['chubb', 'chubb seguros'],
            'mapfre' => ['mapfre', 'mapfre mexico'],
            'inbursa' => ['inbursa', 'seguros inbursa'],
            'hdi' => ['hdi', 'hdi seguros'],
            'banorte' => ['banorte', 'seguros banorte'],
            'metlife' => ['metlife', 'metlife mexico'],
            'monterrey' => ['monterrey', 'seguros monterrey', 'new york life'],
            'atlas' => ['atlas', 'seguros atlas'],
            'afirme' => ['afirme', 'afirme seguros'],
            'zurich' => ['zurich', 'zurich seguros'],
            'allianz' => ['allianz', 'allianz mexico'],
            'ana' => ['ana seguros', 'ana compania de seguros'],
            'bx+' => ['ve por mas', 'bx+', 'seguros bx+'],
            'potasi' => ['el potosi', 'seguros el potosi'],
            'bupa' => ['bupa', 'bupa mexico'],
            'plan seguro' => ['plan seguro'],
            'sofimex' => ['sofimex'],
        ];

        $matchedCandidates = $aseguradoras->filter(function (Aseguradora $a) use ($normDetected, $aliases) {
            $catNorm = $this->normalizeSearchText($a->nombre);
            if ($catNorm === '') {
                return false;
            }
            if (str_contains($normDetected, $catNorm) || str_contains($catNorm, $normDetected)) {
                return true;
            }
            foreach ($aliases as $synonyms) {
                $detectedHasAlias = false;
                $catHasAlias = false;
                foreach ($synonyms as $syn) {
                    if (preg_match('/\b' . preg_quote($syn, '/') . '\b/u', $normDetected)) {
                        $detectedHasAlias = true;
                    }
                    if (preg_match('/\b' . preg_quote($syn, '/') . '\b/u', $catNorm)) {
                        $catHasAlias = true;
                    }
                }
                if ($detectedHasAlias && $catHasAlias) {
                    return true;
                }
            }

            return false;
        })->values();

        if ($matchedCandidates->count() === 1) {
            $m = $matchedCandidates->first();

            return ['id' => (int) $m->id, 'nombre' => $m->nombre];
        }

        $warnings[] = sprintf(
            'La aseguradora detectada («%s») no tuvo una coincidencia única en el catálogo. Selecciónala manualmente.',
            $detectedName
        );

        return ['id' => null, 'nombre' => null];
    }

    /**
     * Resuelve `ramo_id` y `subramo_id` garantizando siempre que `subramo.ramo_id === ramo_id`.
     *
     * @param  list<string>  $warnings
     * @return array{ramo_id: int|null, ramo_nombre: string|null, subramo_id: int|null, subramo_nombre: string|null, tipo_poliza: string|null}
     */
    private function resolveRamoAndSubramo(
        ?string $ramoDetected,
        ?string $subramoDetected,
        ?string $tipoPolizaDetected,
        array &$warnings
    ): array {
        $ramos = Ramo::query()->get(['id', 'nombre'])->keyBy('id');
        $subramos = Subramo::query()->get(['id', 'ramo_id', 'nombre']);

        $normSub = $this->normalizeSearchText($subramoDetected);
        $normRamo = $this->normalizeSearchText($ramoDetected);

        $tipoInferido = $tipoPolizaDetected;
        if (! $tipoInferido) {
            $combined = trim($normSub . ' ' . $normRamo);
            if (str_contains($combined, 'motocicleta') || preg_match('/\bmoto(s)?\b/', $combined)) {
                $tipoInferido = 'moto';
            } elseif (str_contains($combined, 'automovil') || str_contains($combined, 'auto') || str_contains($combined, 'vehiculo')) {
                $tipoInferido = 'auto';
            } elseif (str_contains($combined, 'gastos medicos') || str_contains($combined, 'salud')) {
                $tipoInferido = 'medicos';
            } elseif (str_contains($combined, 'vida')) {
                $tipoInferido = 'vida';
            } elseif (str_contains($combined, 'dano') || str_contains($combined, 'empresarial') || str_contains($combined, 'hogar') || str_contains($combined, 'incendio')) {
                $tipoInferido = 'danos';
            } elseif (str_contains($combined, 'accidente')) {
                $tipoInferido = 'accidentes';
            } elseif (str_contains($combined, 'viaje')) {
                $tipoInferido = 'viaje';
            }
        }

        $targetSubramoKeywords = match ($tipoInferido) {
            'auto' => ['automoviles', 'automovil', 'autos'],
            'moto' => ['motocicletas', 'motocicleta', 'motos'],
            'medicos' => ['gastos medicos mayores', 'gastos medicos'],
            'vida' => ['vida'],
            'danos' => ['danos empresariales', 'danos'],
            'accidentes' => ['accidentes personales'],
            'viaje' => ['viajes', 'seguro de viaje', 'seguro de viajes'],
            default => [],
        };

        $matchedSubramo = null;

        // 1) Coincidencia exacta por nombre de subramo detectado
        if ($normSub !== '') {
            $exactSub = $subramos->filter(fn (Subramo $s) => $this->normalizeSearchText($s->nombre) === $normSub)->values();
            if ($exactSub->count() === 1) {
                $matchedSubramo = $exactSub->first();
            }
        }

        // 2) Coincidencia por tipo de póliza contra catálogo de subramos
        if (! $matchedSubramo && ! empty($targetSubramoKeywords)) {
            $byKeyword = $subramos->filter(function (Subramo $s) use ($targetSubramoKeywords) {
                $sNorm = $this->normalizeSearchText($s->nombre);

                return in_array($sNorm, $targetSubramoKeywords, true);
            })->values();

            if ($byKeyword->count() === 1) {
                $matchedSubramo = $byKeyword->first();
            }
        }

        if ($matchedSubramo) {
            $parentRamo = $ramos->get($matchedSubramo->ramo_id);
            if ($parentRamo) {
                return [
                    'ramo_id' => (int) $parentRamo->id,
                    'ramo_nombre' => $parentRamo->nombre,
                    'subramo_id' => (int) $matchedSubramo->id,
                    'subramo_nombre' => $matchedSubramo->nombre,
                    'tipo_poliza' => $this->resolveTipoPolizaFromCatalogNames($parentRamo->nombre, $matchedSubramo->nombre) ?? $tipoInferido,
                ];
            }
        }

        $warnings[] = 'No se pudo asociar automáticamente el Ramo y Subramo del catálogo. Selecciónalos manualmente.';

        return [
            'ramo_id' => null,
            'ramo_nombre' => null,
            'subramo_id' => null,
            'subramo_nombre' => null,
            'tipo_poliza' => $tipoInferido,
        ];
    }

    /**
     * Resuelve el `agente_id` (`agente_workspaces.id`) exclusivamente dentro del workspace activo
     * y verificando que cuente con clave para la aseguradora seleccionada.
     *
     * @param  array{nombre: string|null, clave_agente: string|null}  $detected
     * @param  list<string>  $warnings
     * @return array{id: int|null, nombre: string|null}
     */
    private function resolveAgenteInWorkspace(
        int $workspaceId,
        ?int $aseguradoraId,
        array $detected,
        array &$warnings
    ): array {
        $claveDetectada = $this->normalizeIdentifier($detected['clave_agente'] ?? null);
        $nombreDetectado = $this->normalizeSearchText($detected['nombre'] ?? null);

        if ($claveDetectada === '' && $nombreDetectado === '') {
            return ['id' => null, 'nombre' => null];
        }

        if ($aseguradoraId === null) {
            $warnings[] = 'Se detectaron datos de agente en el PDF, pero debe seleccionarse primero la aseguradora para validar su clave.';

            return ['id' => null, 'nombre' => null];
        }

        $agentesWorkspace = AgenteWorkspace::query()
            ->with(['agente', 'clavesAseguradora'])
            ->where('workspace_id', $workspaceId)
            ->get()
            ->filter(function (AgenteWorkspace $aw) use ($aseguradoraId) {
                return $aw->clavesAseguradora->contains(
                    fn ($c) => (int) $c->aseguradora_id === $aseguradoraId
                );
            })
            ->values();

        // 1) Coincidencia por clave_agente en la aseguradora correspondiente
        if ($claveDetectada !== '') {
            $byClave = $agentesWorkspace->filter(function (AgenteWorkspace $aw) use ($aseguradoraId, $claveDetectada) {
                return $aw->clavesAseguradora->contains(function ($c) use ($aseguradoraId, $claveDetectada) {
                    return (int) $c->aseguradora_id === $aseguradoraId
                        && $this->normalizeIdentifier($c->clave_agente) === $claveDetectada;
                });
            })->values();

            if ($byClave->count() === 1) {
                $match = $byClave->first();
                $fullName = trim(($match->agente?->nombre ?? '') . ' ' . ($match->agente?->apellido ?? ''));

                return [
                    'id' => (int) $match->id,
                    'nombre' => $fullName !== '' ? $fullName : null,
                ];
            }
        }

        // 2) Coincidencia por nombre completo del agente dentro del workspace
        if ($nombreDetectado !== '') {
            $byName = $agentesWorkspace->filter(function (AgenteWorkspace $aw) use ($nombreDetectado) {
                $fullName = trim(($aw->agente?->nombre ?? '') . ' ' . ($aw->agente?->apellido ?? ''));

                return $this->normalizeSearchText($fullName) === $nombreDetectado;
            })->values();

            if ($byName->count() === 1) {
                $match = $byName->first();
                $fullName = trim(($match->agente?->nombre ?? '') . ' ' . ($match->agente?->apellido ?? ''));

                return [
                    'id' => (int) $match->id,
                    'nombre' => $fullName !== '' ? $fullName : null,
                ];
            }
        }

        $warnings[] = 'El agente detectado en el PDF no coincide con un agente con clave vigente para esta aseguradora en tu promotoría.';

        return ['id' => null, 'nombre' => null];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function normalizeVehiculo(array $v): array
    {
        return [
            'marca' => $this->cleanString($v['marca'] ?? null, 255),
            'modelo' => $this->cleanString($v['modelo'] ?? null, 255),
            'anio' => $this->normalizeInteger($v['anio'] ?? null, 1900, 2100),
            'serie' => $this->cleanUpperCode($v['serie'] ?? null, 255),
            'placas' => $this->cleanUpperCode($v['placas'] ?? null, 50),
            'motor' => $this->cleanUpperCode($v['motor'] ?? null, 255),
            'pasajeros' => $this->normalizeInteger($v['pasajeros'] ?? null, 0, 200),
        ];
    }

    /**
     * @param  array<string, mixed>  $gm
     * @return array<string, mixed>
     */
    private function normalizeGastosMedicos(array $gm): array
    {
        return [
            'nombre_plan' => $this->cleanString($gm['nombre_plan'] ?? null, 255),
            'nivel_hospitalario' => $this->cleanString($gm['nivel_hospitalario'] ?? null, 255),
            'suma_asegurada' => $this->normalizeMoney($gm['suma_asegurada'] ?? null),
            'suma_asegurada_texto' => $this->cleanString($gm['suma_asegurada_texto'] ?? null, 255),
            'deducible' => $this->normalizeMoney($gm['deducible'] ?? null),
            'deducible_texto' => $this->cleanString($gm['deducible_texto'] ?? null, 255),
            'coaseguro_porcentaje' => $this->normalizePercentage($gm['coaseguro_porcentaje'] ?? null),
            'tope_coaseguro' => $this->normalizeMoney($gm['tope_coaseguro'] ?? null),
            'observaciones' => $this->cleanString($gm['observaciones'] ?? null, 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $vj
     * @return array<string, mixed>
     */
    private function normalizeViaje(array $vj): array
    {
        $inicio = $this->normalizeDate($vj['fecha_inicio_viaje'] ?? null);
        $fin = $this->normalizeDate($vj['fecha_fin_viaje'] ?? null);
        if ($inicio && $fin && $fin < $inicio) {
            $fin = null;
        }

        return [
            'nombre_plan' => $this->cleanString($vj['nombre_plan'] ?? null, 255),
            'origen' => $this->cleanString($vj['origen'] ?? null, 255),
            'destino' => $this->cleanString($vj['destino'] ?? null, 255),
            'fecha_inicio_viaje' => $inicio,
            'fecha_fin_viaje' => $fin,
            'observaciones' => $this->cleanString($vj['observaciones'] ?? null, 2000),
        ];
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeAsegurados(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $nombre = $this->cleanString($row['nombre'] ?? null, 255);
            if ($nombre === null) {
                continue;
            }
            $result[] = [
                'nombre' => $nombre,
                'fecha_nacimiento' => $this->normalizeDate($row['fecha_nacimiento'] ?? null),
                'sexo' => $this->cleanString($row['sexo'] ?? null, 30),
                'parentesco' => $this->cleanString($row['parentesco'] ?? null, 100),
                'rfc' => $this->cleanUpperCode($row['rfc'] ?? null, 20),
                'curp' => $this->cleanUpperCode($row['curp'] ?? null, 20),
                'antiguedad' => $this->normalizeDate($row['antiguedad'] ?? null),
                'titular' => $this->normalizeBoolean($row['titular'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeBeneficiarios(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $nombre = $this->cleanString($row['nombre'] ?? null, 255);
            if ($nombre === null) {
                continue;
            }
            $result[] = [
                'nombre' => $nombre,
                'parentesco' => $this->cleanString($row['parentesco'] ?? null, 100),
                'porcentaje' => $this->normalizePercentage($row['porcentaje'] ?? null),
                'fecha_nacimiento' => $this->normalizeDate($row['fecha_nacimiento'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeCoberturas(array $rows): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $nombre = $this->cleanString($row['nombre'] ?? null, 255);
            if ($nombre === null) {
                continue;
            }
            $result[] = [
                'nombre' => $nombre,
                'clave' => $this->cleanString($row['clave'] ?? null, 100),
                'suma_asegurada' => $this->normalizeMoney($row['suma_asegurada'] ?? null),
                'suma_asegurada_texto' => $this->cleanString($row['suma_asegurada_texto'] ?? null, 255),
                'deducible' => $this->normalizeMoney($row['deducible'] ?? null),
                'deducible_texto' => $this->cleanString($row['deducible_texto'] ?? null, 255),
                'coaseguro_porcentaje' => $this->normalizePercentage($row['coaseguro_porcentaje'] ?? null),
                'prima' => $this->normalizeMoney($row['prima'] ?? null),
                'moneda' => $this->normalizeMoneda($row['moneda'] ?? null),
                'observaciones' => $this->cleanString($row['observaciones'] ?? null, 2000),
                'orden' => $index + 1,
            ];
        }

        return $result;
    }

    private function cleanString(mixed $value, int $maxLength = 255): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || strtolower($trimmed) === 'null' || strtolower($trimmed) === 'n/a') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength, 'UTF-8');
    }

    private function cleanUpperCode(mixed $value, int $maxLength = 255): ?string
    {
        $str = $this->cleanString($value, $maxLength);

        return $str !== null ? mb_strtoupper($str, 'UTF-8') : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $raw = trim($value);

        // Formato ISO YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $raw;
            }

            return null;
        }

        // Formato DD/MM/YYYY o DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $raw, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return null;
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeMoney(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }
        if (is_string($value)) {
            $cleaned = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) ?? '';
            if ($cleaned === '' || ! is_numeric($cleaned)) {
                return null;
            }
            $value = (float) $cleaned;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $num = round((float) $value, 2);

        return $num >= 0 ? $num : null;
    }

    private function normalizePercentage(mixed $value): ?float
    {
        $num = $this->normalizeMoney($value);
        if ($num === null || $num < 0 || $num > 100) {
            return null;
        }

        return $num;
    }

    private function normalizeInteger(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            return null;
        }

        return $int;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || (is_string($value) && in_array(strtolower(trim($value)), ['true', 'si', 'sí'], true))) {
            return true;
        }
        if ($value === 0 || $value === '0' || (is_string($value) && in_array(strtolower(trim($value)), ['false', 'no'], true))) {
            return false;
        }

        return null;
    }

    private function normalizeFrecuenciaCobro(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $norm = $this->normalizeSearchText($value);
        if (in_array($norm, ['unico', 'pago unico', 'contado'], true)) {
            return 'unico';
        }
        if (in_array($norm, ['mensual', 'mes'], true)) {
            return 'mensual';
        }
        if (in_array($norm, ['trimestral', 'trimestre'], true)) {
            return 'trimestral';
        }
        if (in_array($norm, ['semestral', 'semestre'], true)) {
            return 'semestral';
        }
        if (in_array($norm, ['anual', 'ano', 'anualidad'], true)) {
            return 'anual';
        }

        return null;
    }

    private function normalizeMoneda(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $upper = strtoupper(trim($value));
        if (in_array($upper, ['MXN', 'MN', 'PESOS', 'PESOS MEXICANOS', 'M.N.'], true)) {
            return 'MXN';
        }
        if (in_array($upper, ['USD', 'DLLS', 'DOLARES', 'DÓLARES'], true)) {
            return 'USD';
        }
        if (in_array($upper, ['EUR', 'EUROS'], true)) {
            return 'EUR';
        }
        if (in_array($upper, ['UDI', 'UDIS'], true)) {
            return 'UDI';
        }

        return mb_substr($upper, 0, 10, 'UTF-8');
    }

    private function normalizeTipoPoliza(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $norm = $this->normalizeSearchText($value);

        return in_array($norm, ['auto', 'moto', 'medicos', 'vida', 'danos', 'accidentes', 'viaje'], true)
            ? $norm
            : null;
    }

    private function resolveTipoPolizaFromCatalogNames(?string $ramoNombre, ?string $subramoNombre): ?string
    {
        $nombre = $this->normalizeSearchText($subramoNombre ?: $ramoNombre);
        if ($nombre === 'automoviles') {
            return 'auto';
        }
        if ($nombre === 'motocicletas') {
            return 'moto';
        }
        if ($nombre === 'vida') {
            return 'vida';
        }
        if ($nombre === 'danos empresariales') {
            return 'danos';
        }
        if (in_array($nombre, ['gastos medicos', 'gastos medicos mayores'], true)) {
            return 'medicos';
        }
        if ($nombre === 'accidentes personales') {
            return 'accidentes';
        }
        if (in_array($nombre, ['viajes', 'seguro de viaje', 'seguro de viajes'], true)) {
            return 'viaje';
        }

        return null;
    }

    private function normalizeSearchText(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $str = mb_strtolower(trim($value), 'UTF-8');
        $replacements = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ];
        $str = strtr($str, $replacements);
        $str = preg_replace('/[^a-z0-9\s+]/u', ' ', $str) ?? $str;
        $str = preg_replace('/\s+/u', ' ', $str) ?? $str;

        return trim($str);
    }

    private function normalizeIdentifier(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }
}

