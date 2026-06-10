<?php

namespace App\Http\Controllers\Api;

use App\Models\Agente;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;

class AgenteController extends BaseApiController
{
    public function index(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::AGENTES_VIEW);

        return Agente::query()
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 15));
    }

    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::AGENTES_MANAGE);
        $data = $request->validate([
            'cedula' => 'required|string|max:255|unique:agentes,cedula',
            'nombre' => 'required|string|max:255',
            'apellido' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:agentes,email',
            'telefono' => 'nullable|string|max:50',
            'fecha_nacimiento' => 'nullable|date',
            'curp' => 'nullable|string|max:18',
            'rfc' => 'nullable|string|max:13',
            'estado' => 'nullable|string|max:100',
            'ciudad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string',
            'fecha_alta' => 'nullable|date',
            'activo' => 'boolean',
            'foto_url' => 'nullable|string|max:2048',
        ]);

        $agente = Agente::create($data);

        return response()->json($agente, 201);
    }

    public function show(Agente $agente)
    {
        $this->requirePermission(request(), WorkspacePermissions::AGENTES_VIEW);

        return $agente;
    }

    public function update(Request $request, Agente $agente)
    {
        $this->requirePermission($request, WorkspacePermissions::AGENTES_MANAGE);
        $data = $request->validate([
            'cedula' => 'sometimes|required|string|max:255|unique:agentes,cedula,'.$agente->id,
            'nombre' => 'sometimes|required|string|max:255',
            'apellido' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:agentes,email,'.$agente->id,
            'telefono' => 'nullable|string|max:50',
            'fecha_nacimiento' => 'nullable|date',
            'curp' => 'nullable|string|max:18',
            'rfc' => 'nullable|string|max:13',
            'estado' => 'nullable|string|max:100',
            'ciudad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string',
            'fecha_alta' => 'nullable|date',
            'activo' => 'boolean',
            'foto_url' => 'nullable|string|max:2048',
        ]);

        $agente->update($data);

        return $agente->fresh();
    }

    public function destroy(Agente $agente)
    {
        $this->requirePermission(request(), WorkspacePermissions::AGENTES_MANAGE);
        $agente->delete();

        return response()->noContent();
    }
}
