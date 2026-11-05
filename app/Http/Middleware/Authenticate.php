<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * Everything under /api answers 401 JSON instead -- the SPA (and the portal) handle a 401
     * themselves, and EventSource can't send an Accept header to ask for JSON.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() || $request->is('api/*') ? null : url('/');
    }
}
