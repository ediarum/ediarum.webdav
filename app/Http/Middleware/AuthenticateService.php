<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-side endpoints of the auth contract (ediarum.platform docs/auth-contract.md): callers are
 * app backends, not browsers, and authenticate with `Authorization: Bearer <SERVICE_TOKEN>` instead
 * of a session. Without a configured token the endpoints are off (401), never open.
 */
class AuthenticateService
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.platform.service_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || !hash_equals($expected, $given)) {
            return response()->noContent(401);
        }

        return $next($request);
    }
}
