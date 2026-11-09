<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poliza_asegurados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poliza_id')->constrained('polizas')->cascadeOnDelete();
            $table->string('nombre');
            $table->date('fecha_nacimiento')->nullable();
            $table->string('sexo', 30)->nullable();
            $table->string('parentesco', 100)->nullable();
            $table->string('rfc', 20)->nullable();
            $table->string('curp', 20)->nullable();
            $table->date('antiguedad')->nullable();
            $table->boolean('titular')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poliza_asegurados');
    }
};
