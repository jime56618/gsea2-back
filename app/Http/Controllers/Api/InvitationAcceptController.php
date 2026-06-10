<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkspaceUser;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

/**
 * Aceptar invitación a una promotoría (usuario nuevo o existente).
 * No inicia trial: el trial es solo al crear la promotoría.
 */
class InvitationAcceptController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
    ) {}

    /** Público: vista previa de invitación por token */
    public function showByToken(string $token): JsonResponse
    {
        $invitation = $this->findValidInvitation($token);

        return response()->json([
            'email' => $invitation->email,
            'workspace' => $invitation->workspace->only(['id', 'nombre', 'slug']),
            'role' => $invitation->role->only(['id', 'nombre', 'slug']),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'name' => 'nullable|string|max:255',
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ]);

        $invitation = $this->findValidInvitation($data['token']);
        $workspace = $invitation->workspace;

        if (! $this->subscriptions->canAddUser($workspace)) {
            return response()->json([
                'message' => 'La promotoría alcanzó el límite de usuarios del plan.',
                'code' => 'plan_limit_users',
            ], 422);
        }

        $user = User::where('email', $invitation->email)->first();

        if (! $user) {
            if (empty($data['name']) || empty($data['password'])) {
                return response()->json([
                    'message' => 'Se requiere name y password para crear la cuenta.',
                    'code' => 'registration_required',
                ], 422);
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
            ]);
        }

        if (! $this->roleAssignable($invitation->role, $workspace->id)) {
            return response()->json(['message' => 'Rol no válido para esta promotoría.'], 422);
        }

        DB::transaction(function () use ($user, $invitation, $workspace) {
            WorkspaceUser::updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                ],
                ['role_id' => $invitation->role_id]
            );

            if (empty($user->current_workspace_id)) {
                $user->current_workspace_id = $workspace->id;
                $user->save();
            }

            $invitation->update(['used' => true]);
        });

        return response()->json([
            'message' => 'Invitación aceptada. Ya puedes iniciar sesión.',
            'workspace_id' => $workspace->id,
            'email' => $user->email,
        ]);
    }

    private function findValidInvitation(string $token): Invitation
    {
        $invitation = Invitation::query()
            ->where('token', $token)
            ->with(['workspace', 'role'])
            ->first();

        if (! $invitation || $invitation->used) {
            abort(response()->json(['message' => 'Invitación inválida o ya utilizada.'], 404));
        }

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            abort(response()->json(['message' => 'La invitación expiró.'], 410));
        }

        return $invitation;
    }

    private function roleAssignable(Role $role, int $workspaceId): bool
    {
        if ($role->is_system && $role->workspace_id === null) {
            return true;
        }

        return (int) $role->workspace_id === $workspaceId;
    }
}
