<?php

namespace App\Http\Controllers\Api;

use App\Models\Invitation;
use App\Mail\InvitationMail;
use Illuminate\Support\Facades\Mail;
use App\Services\Authorization\WorkspaceAuthorizationService;
use App\Services\Subscription\SubscriptionService;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends BaseApiController
{
    public function __construct(
        private WorkspaceAuthorizationService $authorization,
        private SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::USERS_INVITE);
        $workspaceId = $this->resolveWorkspaceId($request);

        return Invitation::query()
            ->with(['workspace', 'role'])
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));
    }

    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::USERS_INVITE);
        $workspaceId = $this->resolveWorkspaceId($request);

        $workspace = $request->user()->currentWorkspace;

        if (! $this->subscriptions->canAddUser($workspace)) {
            return response()->json([
                'message' => 'Límite de usuarios del plan alcanzado.',
                'code' => 'plan_limit_users',
            ], 422);
        }

        $data = $request->validate([
            'email' => 'required|email|max:255',
            'role_id' => 'required|exists:roles,id',
            'expires_at' => 'nullable|date',
        ]);

        $role = \App\Models\Role::findOrFail($data['role_id']);

        if (! $this->authorization->roleBelongsToWorkspace($role, $workspaceId)) {
            return response()->json(['message' => 'Rol no válido para esta promotoría.'], 422);
        }

        $data['workspace_id'] = $workspaceId;
        $data['token'] = Str::random(64);
        $data['used'] = false;

        $invitation = Invitation::create($data);

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173'); 

        $inviteUrl = rtrim($frontendUrl, '/') . '/invitacion/' . $invitation->token;
        
        Mail::to($invitation->email)
            ->send(new InvitationMail($invitation, $inviteUrl));
        
        return response()->json(
            $invitation->load(['workspace', 'role']),
            201
        );
    }

    public function show(Request $request, Invitation $invitation)
    {
        $this->abortIfOutOfWorkspace($request, $invitation->workspace_id);
        $this->requirePermission($request, WorkspacePermissions::USERS_INVITE);

        return $invitation->load(['workspace', 'role']);
    }

    public function update(Request $request, Invitation $invitation)
    {
        $this->abortIfOutOfWorkspace($request, $invitation->workspace_id);
        $this->requirePermission($request, WorkspacePermissions::USERS_INVITE);

        $data = $request->validate([
            'email' => 'sometimes|required|email|max:255',
            'role_id' => 'sometimes|required|exists:roles,id',
            'used' => 'boolean',
            'expires_at' => 'nullable|date',
        ]);

        if (isset($data['role_id'])) {
            $role = \App\Models\Role::findOrFail($data['role_id']);
            if (! $this->authorization->roleBelongsToWorkspace($role, $invitation->workspace_id)) {
                return response()->json(['message' => 'Rol no válido.'], 422);
            }
        }

        $invitation->update($data);

        return $invitation->fresh()->load(['workspace', 'role']);
    }

    public function destroy(Request $request, Invitation $invitation)
    {
        $this->abortIfOutOfWorkspace($request, $invitation->workspace_id);
        $this->requirePermission($request, WorkspacePermissions::USERS_INVITE);

        $invitation->delete();

        return response()->noContent();
    }
}
