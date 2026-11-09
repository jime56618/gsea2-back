<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poliza_viajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poliza_id')->constrained('polizas')->cascadeOnDelete();
            $table->unique('poliza_id');
            $table->string('nombre_plan')->nullable();
            $table->string('origen')->nullable();
            $table->string('destino')->nullable();
            $table->date('fecha_inicio_viaje')->nullable();
            $table->date('fecha_fin_viaje')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poliza_viajes');
    }
};
