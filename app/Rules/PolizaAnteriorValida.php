<?php

namespace App\Rules;

use App\Models\Poliza;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Schema;

class PolizaAnteriorValida implements ValidationRule
{
    public function __construct(private int $workspaceId, private ?int $polizaId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Schema::hasColumn('polizas', 'poliza_anterior_id')) {
            $fail('El vínculo de renovación requiere aplicar su migración.');
            return;
        }
        $id = (int) $value;
        $visited = $this->polizaId ? [$this->polizaId => true] : [];
        while ($id) {
            if (isset($visited[$id])) {
                $fail('La renovación no puede vincularse consigo misma ni formar ciclos.');
                return;
            }
            $visited[$id] = true;
            $previous = Poliza::where('workspace_id', $this->workspaceId)->find($id, ['id', 'poliza_anterior_id']);
            if (! $previous) {
                $fail('La póliza anterior y su cadena deben pertenecer al workspace activo.');
                return;
            }
            $id = (int) $previous->poliza_anterior_id;
        }
    }
}
