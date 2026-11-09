<?php

namespace App\Services\Exports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Illuminate\Validation\ValidationException;

class XlsxExporter
{
    public const MAX_ROWS = 200000;

    /** Devuelve archivo temporal completo: cualquier fallo ocurre antes de enviar la descarga. */
    public function write(iterable $rows, array $columns): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gsea-xlsx-');
        if ($path === false) throw new \RuntimeException('No fue posible crear el archivo temporal.');
        $writer = new Writer(); $opened = false;
        try {
            $writer->openToFile($path); $opened = true;
            $writer->addRow(new Row(array_map(fn ($label) => new StringCell($label, null), array_keys($columns))));
            $count = 0;
            foreach ($rows as $row) {
                if (++$count > self::MAX_ROWS) throw ValidationException::withMessages(['exportacion'=>'La exportación supera 200 000 filas. Reduce los filtros.']);
                $cells = [];
                foreach ($columns as [$field, $type]) {
                    $value = $row[$field] ?? null;
                    if ($value === null || $value === '') { $cells[] = Cell::fromValue(null); continue; }
                    if ($type === 'number') {
                        $cells[] = Cell::fromValue((float) $value, (new Style())->setFormat('#,##0.00'));
                    } elseif ($type === 'date') {
                        $cells[] = Cell::fromValue(new \DateTimeImmutable(substr((string) $value, 0, 10)), (new Style())->setFormat('dd/mm/yyyy'));
                    } else {
                        // StringCell explícita: nunca FormulaCell, incluso para =, +, -, @ o tabuladores.
                        $cells[] = new StringCell(mb_substr((string) $value, 0, 32767), null);
                    }
                }
                $writer->addRow(new Row($cells));
            }
            if (! $count) throw ValidationException::withMessages(['exportacion'=>'No hay registros para exportar.']);
            $writer->close(); $opened = false;
            return $path;
        } catch (\Throwable $e) {
            if ($opened) { try { $writer->close(); } catch (\Throwable) {} }
            @unlink($path); throw $e;
        }
    }
}
