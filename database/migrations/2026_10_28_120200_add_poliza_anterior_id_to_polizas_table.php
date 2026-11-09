<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polizas', function (Blueprint $table) {
            $table->foreignId('poliza_anterior_id')->nullable()->constrained('polizas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('polizas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('poliza_anterior_id');
        });
    }
};
