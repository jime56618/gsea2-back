<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poliza_beneficiarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poliza_id')->constrained('polizas')->cascadeOnDelete();
            $table->foreignId('asegurado_id')->nullable()->constrained('poliza_asegurados')->nullOnDelete();
            $table->string('nombre');
            $table->string('parentesco', 100)->nullable();
            $table->decimal('porcentaje', 5, 2)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poliza_beneficiarios');
    }
};
