<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles por promotoría: workspace_id null = plantillas de sistema (admin, operador...).
 * workspace_id set = rol custom creado en el editor de permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('id')
                ->constrained('workspaces')->cascadeOnDelete();
            $table->string('slug', 64)->nullable()->after('nombre');
            $table->boolean('is_system')->default(false)->after('slug');
            $table->string('description')->nullable()->after('is_system');

            $table->unique(['workspace_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'slug']);
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['slug', 'is_system', 'description']);
        });
    }
};
