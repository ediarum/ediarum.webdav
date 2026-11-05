<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The platform's auth provider (/api/auth/…): the browser side for ediarum.portal's login and
 * profile pages, and verify() for the other apps' backends. The spec is ediarum.platform's
 * docs/auth-contract.md; see also planning/portal-integration.md.
 */
class AuthController extends Controller
{
    /**
     * Nothing to do: the `web` middleware group's VerifyCsrfToken attaches a fresh XSRF-TOKEN cookie
     * to every response. This is just a cheap request to get one.
     */
    public function csrfCookie(): Response
    {
        return response()->noContent();
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function password(Request $request): Response
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->noContent();
    }

    /**
     * Users may change their own display name, not their email: the email is how app backends
     * identify them (X-User), so it stays fixed. Answers with the updated user, so the portal can
     * refresh its top bar.
     */
    public function profile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update(['name' => trim($validated['name'])]);

        return response()->json($this->userPayload($user));
    }

    /**
     * The projects the user may access, for pickers in the portal and the apps: their own, or all
     * of them for admins (the access rule in docs/auth-contract.md, "Projects").
     */
    public function projects(Request $request): JsonResponse
    {
        $user = $request->user();
        $projects = $user->is_admin ? Project::query() : $user->projects();

        return response()->json(
            $projects->orderBy('name')->get(['projects.slug', 'projects.name'])
                ->map(fn (Project $p) => ['slug' => $p->slug, 'name' => $p->name])
                ->values()
        );
    }

    /**
     * For app backends (ediarum.zotero, ediarum.index) to find out who sent a request: they call
     * this with the browser's Cookie header, the original method as X-Original-Method and, if
     * present, its X-XSRF-TOKEN. 204 plus who the user is, 401 without a session, 419 for a write
     * without a valid CSRF token. See ediarum.platform docs/auth-contract.md, "Service side".
     * The user is identified by email (unique, and safe in a header), not by display name.
     */
    public function verify(Request $request, Encrypter $encrypter): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->noContent(401);
        }

        $method = strtoupper($request->header('X-Original-Method') ?: 'GET');

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !$this->xsrfTokenMatches($request, $encrypter)) {
            return response()->noContent(419);
        }

        return response()->noContent()->withHeaders([
            'X-User' => $user->email,
            'X-User-Roles' => implode(',', $user->roles()),
            'X-User-Projects' => implode(',', $user->projectSlugs()),
        ]);
    }

    /**
     * The X-XSRF-TOKEN header checked the way VerifyCsrfToken checks it: it is the encrypted
     * XSRF-TOKEN cookie value, which must decrypt to this session's token.
     */
    private function xsrfTokenMatches(Request $request, Encrypter $encrypter): bool
    {
        $header = $request->header('X-XSRF-TOKEN');
        $sessionToken = $request->session()->token();

        if (!is_string($header) || $header === '' || !is_string($sessionToken)) {
            return false;
        }

        try {
            $token = CookieValuePrefix::remove($encrypter->decrypt($header, VerifyCsrfToken::serialized()));
        } catch (DecryptException) {
            return false;
        }

        return is_string($token) && hash_equals($sessionToken, $token);
    }

    private function userPayload(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles(),
            'projects' => $user->projectSlugs(),
        ];
    }
}
