<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class UsersController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            User::orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'isAdmin' => (bool) $u->is_admin,
                ])
                ->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        return response()->json(['id' => $user->id], 201);
    }

    /**
     * Sets a new random password and returns it once -- the admin passes it on to the user.
     */
    public function resetPassword(string $userId): JsonResponse
    {
        $user = User::where('id', $userId)->firstOrFail();

        $newPassword = Str::random(10);
        $user->password = Hash::make($newPassword);
        $user->save();

        return response()->json(['name' => $user->name, 'password' => $newPassword]);
    }
}
