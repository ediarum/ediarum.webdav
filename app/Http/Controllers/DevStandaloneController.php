<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Local development only (APP_ENV=local, a 404 everywhere else): the admin frontend without the
 * ediarum.platform portal. /webdav/… serves a page that mounts the built embed.js the way the
 * portal does, logged in as the first admin (the one `db:seed` creates), so there is no login
 * page. The real login, /api/auth/*, is untouched; the portal setup is still the way to test it.
 * See documentation/DEVELOPMENT.md.
 */
class DevStandaloneController extends Controller
{
    public function root(): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        return redirect('/webdav/');
    }

    public function page(Request $request): Response
    {
        abort_unless(app()->isLocal(), 404);

        // An existing session (e.g. from the portal on another port of the same host) is kept.
        $user = $request->user();
        if (!$user) {
            $user = User::where('is_admin', true)->orderBy('id')->first();
            if (!$user) {
                return response('No admin user yet. Run `php artisan db:seed` with INITIAL_USER_* set.', 500)
                    ->header('Content-Type', 'text/plain');
            }
            Auth::login($user);
            $request->session()->regenerate();
        }

        return response()->view('dev.standalone', [
            'ctx' => [
                'routerBase' => '/webdav/',
                'apiBase' => '/api/webdav',
                'user' => ['name' => $user->name, 'roles' => $user->roles()],
            ],
        ]);
    }
}
