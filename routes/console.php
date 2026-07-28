<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bloqueo automático de promotorías con trial vencido (sin pago).
Schedule::command('subscriptions:expire-trials')->daily();

Schedule::command('google-calendar:sync')->everyMinute();

Schedule::command('whatsapp:send-due-reminders')->dailyAt('09:00');