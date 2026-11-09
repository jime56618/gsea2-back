<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poliza_coberturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poliza_id')->constrained('polizas')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('clave', 100)->nullable();
            $table->decimal('suma_asegurada', 18, 2)->nullable();
            $table->string('suma_asegurada_texto')->nullable();
            $table->decimal('deducible', 18, 2)->nullable();
            $table->string('deducible_texto')->nullable();
            $table->decimal('coaseguro_porcentaje', 5, 2)->nullable();
            $table->decimal('prima', 18, 2)->nullable();
            $table->string('moneda', 10)->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poliza_coberturas');
    }
};
