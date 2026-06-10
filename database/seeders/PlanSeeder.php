<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Plan comercial Pro (trial 14 días configurado en workspace.php al crear promotoría). */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'nombre' => 'Profesional',
                'precio_mensual' => 999.00,
                'trial_days' => 14,
                'is_public' => true,
                'description' => 'Plan completo para promotorías de seguros.',
                'limits' => [
                    'users' => 25,
                    'polizas' => 10000,
                    'agentes' => 100,
                ],
                'features' => [
                    'dashboard' => true,
                    'cobranza' => true,
                    'calendar' => true,
                    'tramites' => true,
                    'roles_editor' => true,
                ],
            ]
        );
    }
}
