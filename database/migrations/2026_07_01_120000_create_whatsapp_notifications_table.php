<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poliza_id')->constrained('polizas')->cascadeOnDelete();
            $table->foreignId('cobranza_cuota_id')->nullable()->constrained('cobranza_cuotas')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('telefono', 30);
            $table->text('mensaje');
            $table->string('estatus', 20)->default('pendiente');
            $table->string('twilio_sid')->nullable();
            $table->date('programado_para');
            $table->timestamp('enviado_en')->nullable();
            $table->text('error_detalle')->nullable();
            $table->timestamps();

            $table->index(['estatus', 'programado_para']);
            $table->unique(['cobranza_cuota_id', 'tipo'], 'wa_notif_cuota_tipo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notifications');
    }
};
