<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterWorkspaceForOwner;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    public function __construct(private RegisterWorkspaceForOwner $registerWorkspace) {}

    /**
     * Registro web: usuario + promotoría con trial 14 días.
     *
     * @throws ValidationException
     */
    public function store(Request $request): Response
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'workspace' => ['required', 'string', 'max:255'],
        ]);

        $user = null;

        DB::transaction(function () use ($request, &$user) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->string('password')),
            ]);

            $this->registerWorkspace->execute($user, $request->workspace);
        });

        event(new Registered($user));

        Auth::login($user);

        return response()->noContent();
    }
}
