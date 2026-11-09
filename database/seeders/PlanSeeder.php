<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'simple'],
            [
                'nombre' => 'Simple',
                'precio_mensual' => 1200.00,
                'trial_days' => 14,
                'is_public' => true,
                'description' => 'Agente independiente o con asistente.',
                'limits' => [
                    'users' => 2,
                    'polizas' => 350,
                    'agentes' => 5,
                ],
                'features' => [
                    'Hasta 350 pólizas',
                    'Gestión de clientes y pólizas',
                    'Seguimiento básico de trámites',
                    'Dashboard básico',
                    'Historial de pólizas',
                    'Recordatorios manuales',
                ],
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'crm'],
            [
                'nombre' => 'CRM Operativo',
                'precio_mensual' => 2300.00,
                'trial_days' => 14,
                'is_public' => true,
                'description' => 'Pequeña agencia o agente con equipo.',
                'limits' => [
                    'users' => 10,
                    'polizas' => 750,
                    'agentes' => 25,
                ],
                'features' => [
                    'Todo lo del plan Simple',
                    'Hasta 750 pólizas',
                    'Historial de pólizas',
                    'Reportes básicos',
                    'Dashboard operativo',
                    'Agentes de solo lectura',
                ],
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'nombre' => 'Pro',
                'precio_mensual' => 3500.00,
                'trial_days' => 14,
                'is_public' => true,
                'description' => 'Promotoría con agentes externos.',
                'limits' => [
                    'users' => 25,
                    'polizas' => 10000,
                    'agentes' => 100,
                ],
                'features' => [
                    'Todo lo de CRM Operativo',
                    'Hasta 10,000 pólizas',
                    'KPIs y dashboards en tiempo real',
                    'Proyección de renovaciones e ingresos',
                    'Roles y permisos personalizados',
                    'Auditoría y control de duplicados',
                ],
            ]
        );
    }
}