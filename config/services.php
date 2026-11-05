<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'git_remote' => [
        'master_username' => env('GIT_MASTER_USERNAME'),
        'master_access_token' => env('GIT_MASTER_ACCESS_TOKEN'),
        'push_timeout' => env('GIT_PUSH_TIMEOUT', 60),
    ],

    // Where PushToEdiarumServices sends the changes of every project that has
    // push_to_ediarum_services turned on (POST {url}/files, the project named by slug in the body),
    // and the Bearer token it sends along. Both set, or nothing is pushed.
    'ediarum_services' => [
        'url' => env('EDIARUM_SERVICES_URL'),
        'api_key' => env('EDIARUM_SERVICES_API_KEY'),
    ],

    // Bearer token of the platform's service-side endpoints (GET /api/auth/service/…), shared with
    // the app backends that call them. Unset: those endpoints answer 401.
    'platform' => [
        'service_token' => env('SERVICE_TOKEN'),
    ],

];
