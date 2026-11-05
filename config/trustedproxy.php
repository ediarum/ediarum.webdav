<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Read by App\Http\Middleware\TrustProxies. Behind ediarum.platform's reverse proxy (the
    | container is not reachable otherwise), set TRUSTED_PROXIES=* so Laravel takes the client IP
    | and scheme from X-Forwarded-*: the login rate limit is per client IP, and without this every
    | user would share the proxy's. A comma-separated list of IPs/CIDRs also works.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
