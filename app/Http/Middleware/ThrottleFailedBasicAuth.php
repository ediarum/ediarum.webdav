<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brute-force protection for the WebDAV endpoint, which checks passwords with HTTP Basic auth on
 * every request. Runs before `auth.basic` and counts only failed logins: 401 responses to requests
 * that sent credentials. Oxygen's first request without credentials (the auth challenge) and
 * everything that authenticates don't count, so working clients are never slowed down.
 *
 * Two limits: per username and IP (guessing one account's password), and per IP (a bot cycling
 * through usernames). Once either is reached, every request from there gets 429 until the window
 * ends -- with the right password too, so a blocked client can't tell whether a guess was right.
 */
class ThrottleFailedBasicAuth
{
    public const MAX_PER_USER = 10;

    public const MAX_PER_IP = 30;

    public const DECAY_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $keys = $this->keys($request);

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response('Too many failed login attempts.', 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401 && (string) $request->getUser() !== '') {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }
        }

        return $response;
    }

    /**
     * @return array<string, int> rate limiter key => maximum failures
     */
    private function keys(Request $request): array
    {
        $ip = (string) $request->ip();
        $user = Str::lower((string) $request->getUser());

        return [
            'webdav-basic-auth|' . $user . '|' . $ip => self::MAX_PER_USER,
            'webdav-basic-auth|' . $ip => self::MAX_PER_IP,
        ];
    }
}
