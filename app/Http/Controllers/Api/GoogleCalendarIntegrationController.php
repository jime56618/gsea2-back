<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoogleIntegration;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Oauth2;
use Illuminate\Http\Request;

class GoogleCalendarIntegrationController extends Controller
{
    private function googleClient(): Client
    {
        $client = new Client();

        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));

        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $client->addScope('https://www.googleapis.com/auth/calendar');
        $client->addScope('https://www.googleapis.com/auth/userinfo.email');

        return $client;
    }

    public function status(Request $request)
    {
        $integration = GoogleIntegration::where('user_id', $request->user()->id)
            ->where('provider', 'google_calendar')
            ->first();

        return response()->json([
            'connected' => (bool) $integration,
            'google_email' => $integration?->google_email,
            'expires_at' => $integration?->expires_at,
        ]);
    }

    public function redirect(Request $request)
    {
        $client = $this->googleClient();

        $client->setState((string) $request->user()->id);

        return response()->json([
            'url' => $client->createAuthUrl(),
        ]);
    }

    public function callback(Request $request)
    {
        if (! $request->has('code')) {
            return response()->json(['message' => 'Código de Google no recibido.'], 422);
        }

        $userId = $request->get('state');
        if (! $userId) {
    return redirect(env('FRONTEND_URL', 'http://localhost:5173') . '/integraciones?google_calendar=error');
}

        $client = $this->googleClient();

        $token = $client->fetchAccessTokenWithAuthCode($request->get('code'));

        if (isset($token['error'])) {
            return response()->json([
                'message' => 'Error al conectar Google Calendar.',
                'error' => $token,
            ], 422);
        }

        $client->setAccessToken($token);

        $oauth = new Oauth2($client);
        $googleUser = $oauth->userinfo->get();

        GoogleIntegration::updateOrCreate(
            [
                'user_id' => $userId,
                'provider' => 'google_calendar',
            ],
            [
                'google_email' => $googleUser->email,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'expires_at' => isset($token['expires_in'])
                    ? Carbon::now()->addSeconds($token['expires_in'])
                    : null,
            ]
        );

        return redirect(env('FRONTEND_URL', 'http://localhost:5173') . '/integraciones?google_calendar=connected');
    }

    public function disconnect(Request $request)
    {
        GoogleIntegration::where('user_id', $request->user()->id)
            ->where('provider', 'google_calendar')
            ->delete();

        return response()->json([
            'message' => 'Google Calendar desconectado correctamente.',
        ]);
    }
}