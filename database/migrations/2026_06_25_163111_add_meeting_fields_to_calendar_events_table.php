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
        $table->string('meeting_provider')->nullable()->after('status');
        $table->string('meet_link')->nullable()->after('meeting_provider');
        $table->string('attendee_name')->nullable()->after('meet_link');
        $table->string('attendee_email')->nullable()->after('attendee_name');
    });
}

public function down(): void
{
    Schema::table('calendar_events', function (Blueprint $table) {
        $table->dropColumn([
            'meeting_provider',
            'meet_link',
            'attendee_name',
            'attendee_email',
        ]);
    });
}
};
