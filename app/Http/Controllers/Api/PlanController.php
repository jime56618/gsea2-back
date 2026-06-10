<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        return Plan::query()
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'slug' => 'nullable|string|max:64|unique:planes,slug',
            'precio_mensual' => 'nullable|numeric|min:0',
            'trial_days' => 'nullable|integer|min:0',
            'limits' => 'nullable|array',
            'features' => 'nullable|array',
            'is_public' => 'boolean',
            'description' => 'nullable|string',
        ]);

        $plan = Plan::create($data);

        return response()->json($plan, 201);
    }

    public function show(Plan $plan)
    {
        return $plan;
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|max:64|unique:planes,slug,'.$plan->id,
            'precio_mensual' => 'nullable|numeric|min:0',
            'trial_days' => 'nullable|integer|min:0',
            'limits' => 'nullable|array',
            'features' => 'nullable|array',
            'is_public' => 'boolean',
            'description' => 'nullable|string',
        ]);

        $plan->update($data);

        return $plan->fresh();
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();

        return response()->noContent();
    }
}
