<?php

namespace App\Console\Commands;


use App\Models\CalendarEvent;
use App\Models\GoogleIntegration;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Calendar as GoogleCalendar;
use Illuminate\Console\Command;

class SyncGoogleCalendarEvents extends Command
{
    protected $signature = 'google-calendar:sync';
    protected $description = 'Sincroniza eventos desde Google Calendar hacia GSEA CRM';

    public function handle(): int
    {
        $integrations = GoogleIntegration::where('provider', 'google_calendar')->get();

        foreach ($integrations as $integration) {
            $client = new Client();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setAccessToken([
                'access_token' => $integration->access_token,
                'refresh_token' => $integration->refresh_token,
            ]);

            if ($client->isAccessTokenExpired() && $integration->refresh_token) {
                $newToken = $client->fetchAccessTokenWithRefreshToken($integration->refresh_token);

                if (! isset($newToken['error'])) {
                    $integration->update([
                        'access_token' => $newToken['access_token'],
                        'expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
                    ]);

                    $client->setAccessToken($newToken);
                }
            }

            $service = new GoogleCalendar($client);

            $googleEvents = $service->events->listEvents('primary', [
                'timeMin' => now()->subMonths(2)->toRfc3339String(),
                'timeMax' => now()->addMonths(6)->toRfc3339String(),
                'singleEvents' => true,
                'orderBy' => 'startTime',
                'showDeleted' => true,
            ]);

            foreach ($googleEvents->getItems() as $googleEvent) {
                $googleEventId = $googleEvent->getId();

                if ($googleEvent->getStatus() === 'cancelled') {
                    CalendarEvent::where('google_event_id', $googleEventId)->delete();
                    continue;
                }

                $startRaw = $googleEvent->start->dateTime ?? $googleEvent->start->date ?? null;
                $endRaw = $googleEvent->end->dateTime ?? $googleEvent->end->date ?? null;

                if (! $startRaw) {
                    continue;
                }

                $start = Carbon::parse($startRaw)->timezone('America/Merida');
                $end = $endRaw
                    ? Carbon::parse($endRaw)->timezone('America/Merida')
                    : $start->copy()->addHour();

                CalendarEvent::updateOrCreate(
                    [
                        'google_event_id' => $googleEventId,
                    ],
                    [
                        'workspace_id' => $integration->user->current_workspace_id,
                        'user_id' => $integration->user_id,
                        'title' => $googleEvent->getSummary() ?: 'Evento sin título',
                        'description' => $googleEvent->getDescription(),
                        'starts_at' => $start->format('Y-m-d H:i:s'),
                        'ends_at' => $end->format('Y-m-d H:i:s'),
                        'color' => '#2563eb',
                    ]
                );
            }
        }

        $this->info('Sincronización de Google Calendar completada.');

        return self::SUCCESS;
    }
}