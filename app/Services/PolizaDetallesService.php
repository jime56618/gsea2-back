<?php

namespace App\Services;

use App\Models\Poliza;
use Illuminate\Validation\ValidationException;

class PolizaDetallesService
{
    public static function rules(): array
    {
        $rules = [];
        $groups = [
            'asegurados' => [
                'nombre' => 'required|string|max:255', 'fecha_nacimiento' => 'nullable|date',
                'sexo' => 'nullable|string|max:30', 'parentesco' => 'nullable|string|max:100',
                'rfc' => 'nullable|string|max:20', 'curp' => 'nullable|string|max:20',
                'antiguedad' => 'nullable|date', 'titular' => 'nullable|boolean',
            ],
            'coberturas' => [
                'nombre' => 'required|string|max:255', 'clave' => 'nullable|string|max:100',
                'suma_asegurada' => 'nullable|numeric|min:0', 'suma_asegurada_texto' => 'nullable|string|max:255',
                'deducible' => 'nullable|numeric|min:0', 'deducible_texto' => 'nullable|string|max:255',
                'coaseguro_porcentaje' => 'nullable|numeric|between:0,100', 'prima' => 'nullable|numeric|min:0',
                'moneda' => 'nullable|string|max:10', 'observaciones' => 'nullable|string', 'orden' => 'nullable|integer|min:0',
            ],
            'beneficiarios' => [
                'asegurado_id' => 'nullable|integer|exists:poliza_asegurados,id',
                'nombre' => 'required|string|max:255', 'parentesco' => 'nullable|string|max:100',
                'porcentaje' => 'nullable|numeric|between:0,100', 'fecha_nacimiento' => 'nullable|date',
            ],
        ];
        foreach ($groups as $group => $fields) {
            $rules[$group] = 'sometimes|array';
            $rules[$group.'.*'] = 'array';
            foreach ($fields as $name => $rule) $rules[$group.'.*.'.$name] = $rule;
        }
        $singles = [
            'gastos_medicos' => [
                'nombre_plan' => 'nullable|string|max:255', 'nivel_hospitalario' => 'nullable|string|max:255',
                'suma_asegurada' => 'nullable|numeric|min:0', 'suma_asegurada_texto' => 'nullable|string|max:255',
                'deducible' => 'nullable|numeric|min:0', 'deducible_texto' => 'nullable|string|max:255',
                'coaseguro_porcentaje' => 'nullable|numeric|between:0,100', 'tope_coaseguro' => 'nullable|numeric|min:0',
                'observaciones' => 'nullable|string',
            ],
            'viaje' => [
                'nombre_plan' => 'nullable|string|max:255', 'origen' => 'nullable|string|max:255',
                'destino' => 'nullable|string|max:255', 'fecha_inicio_viaje' => 'nullable|date',
                'fecha_fin_viaje' => 'nullable|date|after_or_equal:fecha_inicio_viaje',
                'observaciones' => 'nullable|string',
            ],
        ];
        foreach ($singles as $group => $fields) {
            $rules[$group] = 'sometimes|nullable|array';
            foreach ($fields as $name => $rule) $rules[$group.'.'.$name] = $rule;
        }
        return $rules;
    }

    public function sync(Poliza $poliza, array $data): void
    {
        // Solo sincronizar grupos presentes. Arrays vacíos eliminan los elementos de ese grupo.
        // Eliminar beneficiarios antes de asegurados evita referencias cruzadas obsoletas.
        foreach (['beneficiarios', 'asegurados', 'coberturas'] as $key) {
            if (! array_key_exists($key, $data)) continue;
            $relation = $poliza->{$key}();
            if ($key === 'beneficiarios') {
                foreach ($data[$key] as $item) {
                    if (! empty($item['asegurado_id']) && ! $poliza->asegurados()->whereKey($item['asegurado_id'])->exists()) {
                        throw ValidationException::withMessages(['beneficiarios' => 'El asegurado seleccionado no pertenece a esta póliza.']);
                    }
                }
            }
            $relation->delete();
            foreach ($data[$key] as $item) $relation->create($item);
        }
        foreach (['gastos_medicos' => 'gastosMedicos', 'viaje' => 'viaje'] as $key => $relationName) {
            if (! array_key_exists($key, $data)) continue;
            $relation = $poliza->{$relationName}();
            if ($data[$key] === null) $relation->delete();
            else $relation->updateOrCreate(['poliza_id' => $poliza->id], $data[$key]);
        }
    }
}
