<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece planes para límites, features y catálogo público (SaaS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('id');
            $table->unsignedSmallInteger('trial_days')->default(0)->after('precio_mensual');
            $table->json('limits')->nullable()->after('trial_days');
            $table->json('features')->nullable()->after('limits');
            $table->boolean('is_public')->default(true)->after('features');
            $table->text('description')->nullable()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn(['slug', 'trial_days', 'limits', 'features', 'is_public', 'description']);
        });
    }
};
