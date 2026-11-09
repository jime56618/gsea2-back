<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poliza_gastos_medicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poliza_id')->constrained('polizas')->cascadeOnDelete();
            $table->unique('poliza_id');
            $table->string('nombre_plan')->nullable();
            $table->string('nivel_hospitalario')->nullable();
            $table->decimal('suma_asegurada', 18, 2)->nullable();
            $table->string('suma_asegurada_texto')->nullable();
            $table->decimal('deducible', 18, 2)->nullable();
            $table->string('deducible_texto')->nullable();
            $table->decimal('coaseguro_porcentaje', 5, 2)->nullable();
            $table->decimal('tope_coaseguro', 18, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poliza_gastos_medicos');
    }
};
