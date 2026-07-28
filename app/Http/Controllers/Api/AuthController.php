<?php

namespace App\Http\Controllers\Api;

use App\Actions\RegisterWorkspaceForOwner;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthWorkspacePayload;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuthWorkspacePayload $authPayload,
        private RegisterWorkspaceForOwner $registerWorkspace,
    ) {}

    /**
     * Inicio de sesión API: token Sanctum + contexto de promotoría activa.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $this->ensureLoginNotRateLimited($request);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->hitLoginRateLimit($request);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $this->clearLoginRateLimit($request);

        $device = $credentials['device_name'] ?? 'api';
        $token = $user->createToken($device)->plainTextToken;

        $this->ensureCurrentWorkspace($user);
        $user->refresh();

        return response()->json(array_merge(
            $this->authPayload->forUser($user),
            ['token' => $token, 'token_type' => 'Bearer']
        ));
    }

    /**
     * Registro: usuario + promotoría nueva con trial y rol administrador.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'workspace' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = null;

        DB::transaction(function () use ($data, &$user) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $this->registerWorkspace->execute($user, $data['workspace']);
        });

        event(new Registered($user));

        $device = $data['device_name'] ?? 'api';
        $token = $user->createToken($device)->plainTextToken;

        return response()->json(array_merge(
            $this->authPayload->forUser($user->fresh()),
            ['token' => $token, 'token_type' => 'Bearer']
        ), 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureCurrentWorkspace($user);
        $user->refresh();

        return response()->json($this->authPayload->forUser($user));
    }

    public function setCurrentWorkspace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'exists:workspaces,id'],
        ]);

        $user = $request->user();
        $belongs = $user->workspaces()
            ->where('workspaces.id', $data['workspace_id'])
            ->exists();

        if (! $belongs) {
            return response()->json([
                'message' => 'No tienes acceso al workspace indicado.',
            ], 403);
        }

        $user->current_workspace_id = $data['workspace_id'];
        $user->save();

        return response()->json(array_merge(
            ['message' => 'Workspace activo actualizado.'],
            $this->authPayload->forUser($user->fresh())
        ));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Sesión de token cerrada.']);
    }

    private function ensureLoginNotRateLimited(Request $request): void
    {
        try {
            if (! RateLimiter::tooManyAttempts($this->loginThrottleKey($request), 5)) {
                return;
            }

            $seconds = RateLimiter::availableIn($this->loginThrottleKey($request));
        } catch (\Throwable) {
            // Si el cache/DB del rate limit falla, no tumbar el login.
            return;
        }

        throw ValidationException::withMessages([
            'email' => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ])],
        ]);
    }

    private function hitLoginRateLimit(Request $request): void
    {
        try {
            RateLimiter::hit($this->loginThrottleKey($request));
        } catch (\Throwable) {
            // ignore
        }
    }

    private function clearLoginRateLimit(Request $request): void
    {
        try {
            RateLimiter::clear($this->loginThrottleKey($request));
        } catch (\Throwable) {
            // ignore
        }
    }

    private function loginThrottleKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }

    private function ensureCurrentWorkspace(User $user): void
    {
        if (! empty($user->current_workspace_id)) {
            $hasAccess = $user->workspaces()
                ->where('workspaces.id', $user->current_workspace_id)
                ->exists();
            if ($hasAccess) {
                return;
            }
        }

        $firstWorkspaceId = $user->workspaces()->value('workspaces.id');
        $user->current_workspace_id = $firstWorkspaceId;
        $user->save();
    }
}
