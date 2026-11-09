<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metas_comerciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->char('moneda', 3);
            $table->decimal('importe_objetivo', 16, 2);
            $table->timestamps();
            $table->unique(['workspace_id', 'anio', 'mes', 'moneda'], 'metas_comerciales_periodo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_comerciales');
    }
};
