<?php

namespace App\Services\Capacitacion;

use App\Models\CapacitacionConstancia;
use App\Models\CapacitacionCurso;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Servicio de generación y almacenamiento seguro de constancias internas de capacitación en PDF (A4 Horizontal)
 * utilizando exclusivamente Dompdf (barryvdh/laravel-dompdf) con soporte completo para UTF-8 (DejaVu Sans).
 */
class CapacitacionCertificatePdfService
{
    /**
     * Genera y almacena el archivo PDF de una constancia en el disco privado del workspace.
     *
     * @return array{disk: string, path: string, sha256: string}
     */
    public function generateAndStore(CapacitacionConstancia $constancia, ?CapacitacionCurso $curso = null): array
    {
        $disk = $constancia->disk ?: 'local';
        $relativePath = sprintf(
            'capacitaciones/workspace_%d/constancias/%s.pdf',
            (int) $constancia->workspace_id,
            preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) $constancia->folio)
        );

        $pdfBinary = $this->renderPdfBinary($constancia, $curso);
        Storage::disk($disk)->put($relativePath, $pdfBinary);

        return [
            'disk' => $disk,
            'path' => $relativePath,
            'sha256' => hash('sha256', $pdfBinary),
        ];
    }

    /**
     * Renderiza el contenido binario del PDF en formato A4 Horizontal (Landscape) mediante Dompdf.
     */
    public function renderPdfBinary(CapacitacionConstancia $constancia, ?CapacitacionCurso $curso = null): string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) {
            throw new RuntimeException(
                'El paquete barryvdh/laravel-dompdf (dompdf/dompdf) es requerido para generar las constancias PDF.'
            );
        }

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($this->buildHtmlTemplate($constancia, $curso), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Construye la plantilla HTML/CSS limpia e institucional (A4 Horizontal, fondo blanco) para Dompdf.
     * No incluye códigos QR decorativos; muestra exclusivamente el folio único y el código de verificación real
     * respaldados en `capacitacion_constancias`.
     */
    private function buildHtmlTemplate(CapacitacionConstancia $constancia, ?CapacitacionCurso $curso = null): string
    {
        $promotoria = e($constancia->nombre_promotoria);
        $agente = e($constancia->nombre_agente);
        $nombreCurso = e($constancia->nombre_curso);
        $duracion = e($constancia->duracion_texto ?: 'Completado satisfactoriamente');
        $calificacion = $constancia->calificacion_obtenida !== null
            ? number_format((float) $constancia->calificacion_obtenida, 1) . '%'
            : 'Aprobado';
        $fecha = $constancia->fecha_emision
            ? $constancia->fecha_emision->format('d/m/Y')
            : now()->format('d/m/Y');
        $folio = e($constancia->folio);
        $codigo = e($constancia->codigo_verificacion);
        $responsableNombre = e($constancia->responsable_nombre ?: $constancia->nombre_promotoria);
        $responsableCargo = e($constancia->responsable_cargo ?: 'Coordinación de Capacitación');

        $logoImgTag = '';
        if ($curso && ! empty($curso->logo_constancia_path)) {
            if (Storage::disk('local')->exists($curso->logo_constancia_path)) {
                $rawLogo = Storage::disk('local')->get($curso->logo_constancia_path);
                $mime = Storage::disk('local')->mimeType($curso->logo_constancia_path) ?: 'image/png';
                $base64 = base64_encode($rawLogo);
                $logoImgTag = '<img src="data:' . $mime . ';base64,' . $base64 . '" style="max-height:54px;max-width:180px;object-fit:contain;" alt="Logo" />';
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  @page { size: A4 landscape; margin: 0; }
  body {
    margin: 0;
    padding: 0;
    background: #ffffff;
    font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
    color: #0f172a;
  }
  .sheet {
    box-sizing: border-box;
    width: 100%;
    height: 100%;
    padding: 36px 46px;
  }
  .frame-outer {
    border: 3px solid #001F3F;
    padding: 10px;
    height: 90%;
  }
  .frame-inner {
    border: 1px solid #cbd5e1;
    padding: 34px 48px;
    height: 88%;
    position: relative;
  }
  .header {
    width: 100%;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 16px;
    margin-bottom: 24px;
  }
  .promotoria-label {
    font-size: 11px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #64748b;
  }
  .promotoria-name {
    font-size: 18px;
    font-weight: bold;
    color: #001F3F;
    margin-top: 4px;
  }
  .title {
    text-align: center;
    font-size: 36px;
    font-weight: bold;
    letter-spacing: 4px;
    color: #001F3F;
    margin: 18px 0 8px 0;
  }
  .subtitle {
    text-align: center;
    font-size: 13px;
    letter-spacing: 1px;
    color: #475569;
    margin-bottom: 20px;
  }
  .agent-name {
    text-align: center;
    font-size: 28px;
    font-weight: bold;
    color: #1E488F;
    margin: 14px auto;
    padding-bottom: 10px;
    border-bottom: 2px solid #1E488F;
    width: 75%;
  }
  .course-reason {
    text-align: center;
    font-size: 14px;
    color: #334155;
    margin-top: 16px;
  }
  .course-title {
    text-align: center;
    font-size: 22px;
    font-weight: bold;
    color: #0f172a;
    margin: 10px 0 8px 0;
  }
  .course-meta {
    text-align: center;
    font-size: 12px;
    color: #64748b;
    margin-bottom: 30px;
  }
  .footer-table {
    width: 100%;
    margin-top: 34px;
  }
  .sig-line {
    border-top: 1px solid #334155;
    width: 240px;
    margin: 0 auto;
    padding-top: 6px;
    text-align: center;
  }
  .folio-box {
    font-size: 10px;
    color: #475569;
    line-height: 1.6;
  }
  .registry-badge {
    display: inline-block;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 9.5px;
    color: #334155;
    line-height: 1.5;
  }
</style>
</head>
<body>
  <div class="sheet">
    <div class="frame-outer">
      <div class="frame-inner">
        <table class="header">
          <tr>
            <td>
              <div class="promotoria-label">Academia Interna de Capacitación</div>
              <div class="promotoria-name">{$promotoria}</div>
            </td>
            <td style="text-align:right;">{$logoImgTag}</td>
          </tr>
        </table>

        <div class="title">CONSTANCIA</div>
        <div class="subtitle">Se otorga la presente constancia interna de capacitación a:</div>
        <div class="agent-name">{$agente}</div>
        <div class="course-reason">Por haber acreditado satisfactoriamente el curso de capacitación:</div>
        <div class="course-title">«{$nombreCurso}»</div>
        <div class="course-meta">
          Duración estimada: {$duracion} &nbsp;|&nbsp; Calificación: {$calificacion} &nbsp;|&nbsp; Fecha de emisión: {$fecha}
        </div>

        <table class="footer-table">
          <tr>
            <td style="width:36%;" class="folio-box">
              <strong>Folio único:</strong> {$folio}<br>
              <strong>Código de verificación:</strong> {$codigo}<br>
              <span>Documento interno registrado en el expediente de {$promotoria}</span>
            </td>
            <td style="width:34%;text-align:center;">
              <div class="sig-line">
                <strong>{$responsableNombre}</strong><br>
                <span style="font-size:11px;color:#64748b;">{$responsableCargo}</span>
              </div>
            </td>
            <td style="width:30%;text-align:right;">
              <div class="registry-badge">
                <strong>REGISTRO ACADÉMICO GSEA</strong><br>
                Promotoría: {$promotoria}<br>
                Emisión: {$fecha}
              </div>
            </td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
    }
}
