<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramites', function (Blueprint $table) {
            // Históricos sin clasificar: NULL. El controlador asigna el estado inicial de las altas.
            $table->enum('estado', ['en_atencion', 'finalizado', 'cancelado'])->nullable();
            $table->index(['workspace_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('tramites', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'estado']);
            $table->dropColumn('estado');
        });
    }
};
