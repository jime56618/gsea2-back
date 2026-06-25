<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {

            $table->string('type')
                ->default('follow_up')
                ->after('description');

            $table->string('priority')
                ->default('medium')
                ->after('type');

            $table->string('status')
                ->default('pending')
                ->after('priority');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {

            $table->dropColumn([
                'type',
                'priority',
                'status',
            ]);

        });
    }
};