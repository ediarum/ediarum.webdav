<?php

namespace App\Services;

class GitPushFailureClassifier
{
    private const AUTH_FAILURE_SIGNATURES = [
        // Emitted by git's own HTTP client (libcurl) whenever the server rejects credentials over
        // HTTPS -- independent of GitLab's own wording, so this is the most reliable signature.
        'Authentication failed for',
        // GitLab's own response body for bad Basic-auth credentials (wording has stayed stable
        // across GitLab versions, but is still just one signal among several here, not the only one).
        'HTTP Basic: Access denied',
        // curl-level fallback wording git sometimes emits instead of (or alongside) the above,
        // depending on git version and whether the server responded 401 vs. 403.
        'The requested URL returned error: 401',
        'The requested URL returned error: 403',
    ];

    public static function isAuthenticationFailure(string $gitErrorOutput): bool
    {
        foreach (self::AUTH_FAILURE_SIGNATURES as $signature) {
            if (str_contains($gitErrorOutput, $signature)) {
                return true;
            }
        }

        return false;
    }
}
