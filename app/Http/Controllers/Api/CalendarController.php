<?php

namespace App\Http\Controllers\Api;

use App\Models\CalendarEvent;
use App\Models\CobranzaCuota;
use App\Models\GoogleIntegration;
use App\Support\WorkspacePermissions;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\ConferenceSolutionKey;
use Illuminate\Http\Request;

class CalendarController extends BaseApiController
{
    public function cobranzaCuotas(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::CALENDAR_VIEW);
        $workspaceId = $this->resolveWorkspaceId($request);

        $start = $request->filled('year') && $request->filled('month')
            ? Carbon::createFromDate($request->integer('year'), $request->integer('month'), 1)->startOfDay()
            : ($request->date('from')?->startOfDay() ?? now()->startOfMonth());

        $end = $request->filled('year') && $request->filled('month')
            ? $start->copy()->endOfMonth()
            : ($request->date('to')?->endOfDay() ?? now()->endOfMonth());

        return CobranzaCuota::query()
            ->with(['poliza.contratante', 'poliza.aseguradora', 'poliza.agenteWorkspace.agente'])
            ->where('workspace_id', $workspaceId)
            ->whereBetween('fecha_programada', [$start->toDateString(), $end->toDateString()])
            ->orderBy('fecha_programada')
            ->orderBy('numero_cuota')
            ->get();
    }

    public function events(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::CALENDAR_VIEW);
        $workspaceId = $this->resolveWorkspaceId($request);

        return CalendarEvent::where('workspace_id', $workspaceId)
            ->orderBy('starts_at')
            ->get()
            ->map(function ($event) {
                $start = Carbon::parse($event->getRawOriginal('starts_at'), 'America/Merida');
                $endRaw = $event->getRawOriginal('ends_at') ?: $event->getRawOriginal('starts_at');
                $end = Carbon::parse($endRaw, 'America/Merida');

                if ($end->lessThanOrEqualTo($start)) {
                    $end = $start->copy()->addHour();
                }

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'starts_at' => $start->format('Y-m-d\TH:i:s'),
                    'ends_at' => $end->format('Y-m-d\TH:i:s'),
                    'color' => $event->color,
                    'google_event_id' => $event->google_event_id,
                    'type' => $event->type,
                    'priority' => $event->priority,
                    'status' => $event->status,
                    'meeting_provider' => $event->meeting_provider,
                    'meet_link' => $event->meet_link,
                    'attendee_name' => $event->attendee_name,
                    'attendee_email' => $event->attendee_email,
                ];
            });
    }

    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::CALENDAR_VIEW);
        $workspaceId = $this->resolveWorkspaceId($request);

        $data = $this->validateEvent($request);

        $data['type'] = $data['type'] ?? 'task';
        $data['priority'] = $data['priority'] ?? 'medium';
        $data['status'] = $data['status'] ?? 'pending';

        if ($data['type'] !== 'meeting') {
            $data['meeting_provider'] = null;
            $data['meet_link'] = null;
            $data['attendee_name'] = null;
            $data['attendee_email'] = null;
        }

        $event = CalendarEvent::create([
            ...$data,
            'workspace_id' => $workspaceId,
            'user_id' => $request->user()->id,
        ]);

        $this->syncToGoogle($request->user()->id, $event);

        return response()->json($event->fresh(), 201);
    }

    public function update(Request $request, CalendarEvent $event)
    {
        $this->requirePermission($request, WorkspacePermissions::CALENDAR_VIEW);
        $this->abortIfOutOfWorkspace($request, $event->workspace_id);

        $data = $this->validateEvent($request, true);

        $event->update($data);

        $this->syncToGoogle($request->user()->id, $event->fresh());

        return response()->json($event->fresh());
    }

    public function destroy(Request $request, CalendarEvent $event)
    {
        $this->requirePermission($request, WorkspacePermissions::CALENDAR_VIEW);
        $this->abortIfOutOfWorkspace($request, $event->workspace_id);

        $this->deleteFromGoogle($request->user()->id, $event);

        $event->delete();

        return response()->noContent();
    }

    private function validateEvent(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title' => "{$required}|string|max:255",
            'description' => 'nullable|string',
            'starts_at' => "{$required}|date",
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'color' => 'nullable|string|max:20',
            'type' => 'nullable|string|in:task,meeting,follow_up,call,collection,renewal',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'status' => 'nullable|string|in:pending,completed,cancelled',
            'meeting_provider' => 'nullable|string|in:google_meet,none',
            'meet_link' => 'nullable|string|max:500',
            'attendee_name' => 'nullable|string|max:255',
            'attendee_email' => 'nullable|email|max:255',
        ]);
    }

    private function googleService(int $userId): ?GoogleCalendar
    {
        $integration = GoogleIntegration::where('user_id', $userId)
            ->where('provider', 'google_calendar')
            ->first();

        if (! $integration) return null;

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

        return new GoogleCalendar($client);
    }

    private function buildGoogleEvent(CalendarEvent $event): GoogleEvent
    {
        $start = Carbon::parse($event->getRawOriginal('starts_at'), 'America/Merida');
        $endRaw = $event->getRawOriginal('ends_at') ?: $event->getRawOriginal('starts_at');
        $end = Carbon::parse($endRaw, 'America/Merida');

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addHour();
        }

        return new GoogleEvent([
            'summary' => $this->activityIcon($event->type) . ' ' . $event->title,
            'description' => $event->description,
            'start' => [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => 'America/Merida',
            ],
            'end' => [
                'dateTime' => $end->format('Y-m-d\TH:i:s'),
                'timeZone' => 'America/Merida',
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 30],
                    ['method' => 'popup', 'minutes' => 10],
                ],
            ],
            'attendees' => $event->attendee_email ? [
                [
                    'email' => $event->attendee_email,
                    'displayName' => $event->attendee_name,
                ],
            ] : [],
        ]);
    }

    private function syncToGoogle(int $userId, CalendarEvent $event): void
    {
        $service = $this->googleService($userId);
        if (! $service) return;

        $googleEvent = $this->buildGoogleEvent($event);

        $shouldCreateMeet = $event->type === 'meeting'
            && $event->meeting_provider === 'google_meet';

        if ($shouldCreateMeet && ! $event->google_event_id) {
            $conferenceKey = new ConferenceSolutionKey();
            $conferenceKey->setType('hangoutsMeet');

            $conferenceRequest = new CreateConferenceRequest();
            $conferenceRequest->setRequestId(uniqid('gsea-meet-', true));
            $conferenceRequest->setConferenceSolutionKey($conferenceKey);

            $conferenceData = new ConferenceData();
            $conferenceData->setCreateRequest($conferenceRequest);

            $googleEvent->setConferenceData($conferenceData);
        }

        if ($event->google_event_id) {
            $updated = $service->events->update(
                'primary',
                $event->google_event_id,
                $googleEvent,
                [
                    'conferenceDataVersion' => 1,
                    'sendUpdates' => 'all',
                ]
            );

            $event->update([
                'meet_link' => $updated->getHangoutLink() ?: $event->meet_link,
            ]);

            return;
        }

        $created = $service->events->insert(
            'primary',
            $googleEvent,
            [
                'conferenceDataVersion' => 1,
                'sendUpdates' => 'all',
            ]
        );

        $event->update([
            'google_event_id' => $created->id,
            'meet_link' => $created->getHangoutLink(),
        ]);
    }

    private function deleteFromGoogle(int $userId, CalendarEvent $event): void
    {
        if (! $event->google_event_id) return;

        $service = $this->googleService($userId);
        if (! $service) return;

        try {
            $service->events->delete('primary', $event->google_event_id);
        } catch (\Throwable $e) {
            //
        }
    }

    private function activityIcon(?string $type): string
    {
        return match ($type) {
            'call' => '📞',
            'meeting' => '🤝',
            'collection' => '💰',
            'renewal' => '🔄',
            'task' => '📋',
            default => '📄',
        };
    }
}