<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | WebDAV Origin
    |--------------------------------------------------------------------------
    |
    | The origin (scheme and host, no path) that WebDAV clients such as Oxygen connect to. It can
    | differ from APP_URL, e.g. when the admin interface runs on the platform's host while WebDAV
    | is served from another one. The full endpoint shown in the admin interface is
    | this origin + `subpath` (below) + /connection/<slug>. Falls back to APP_URL when unset or
    | empty.
    |
    */

    'webdav_origin' => rtrim(env('WEBDAV_ORIGIN') ?: env('APP_URL', 'http://localhost'), '/'),

    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Subpath
    |--------------------------------------------------------------------------
    |
    | When this app is served behind a reverse proxy under a non-root path,
    | this is that path prefix. Used to build the WebDAV base URI that sabre/dav
    | uses to generate <d:href> values (see App\Sabre\WebDavBaseUri), and the path
    | part of the public WebDAV endpoint after `webdav_origin`. The proxy in front
    | strips it, so Laravel itself only ever sees /connection/<slug>/…. Read via
    | config() rather than env() directly so it still resolves correctly if
    | config caching (`config:cache`) is ever used in deployment.
    |
    */

    'subpath' => env('APP_SUBPATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Git Commit Author
    |--------------------------------------------------------------------------
    |
    | Name and email of the author of every commit webdav makes for a WebDAV change. The editor
    | who made the change is named in the commit message. Set both in the .env; the fallbacks
    | only exist so a missing value never makes a commit fail.
    |
    */

    'git_user_name' => env('APP_GIT_USER_NAME') ?: 'ediarum.webdav',

    'git_user_email' => env('APP_GIT_USER_EMAIL') ?: 'ediarum.webdav@localhost',

    /*
    |--------------------------------------------------------------------------
    | Admin Panel Kill Switch
    |--------------------------------------------------------------------------
    |
    | /admin (AdminController::index) renders failed_jobs.exception text as-is
    | (FailedJob::reason()). A process-level failure (a timeout, above all) can
    | leave a Git remote credential embedded verbatim in that stored text -- see
    | PushToGitRemote::runPush() and GitMergeService::runAuthenticated() for where
    | that's now prevented going forward. This lets the page be pulled out of
    | service via .env alone (ADMIN_PANEL_ENABLED=false), with no deploy, for
    | already-stored rows from before that fix, or any other reason to want it
    | off without touching code.
    |
    */

    'admin_panel_enabled' => env('ADMIN_PANEL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Repositories Directory
    |--------------------------------------------------------------------------
    |
    | Parent directory under which every project's git repository lives, one
    | subdirectory per project slug (see planning/init-project.md). Read via
    | config() rather than env() directly so it still resolves correctly if
    | config caching (`config:cache`) is ever used in deployment.
    |
    */

    'repositories_directory' => env('REPOSITORIES_DIRECTORY'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'Europe/Berlin',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => 'file',
        // 'store'  => 'redis',
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => ServiceProvider::defaultProviders()->merge([
        /*
         * Package Service Providers...
         */

        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => Facade::defaultAliases()->merge([
        // 'Example' => App\Facades\Example::class,
    ])->toArray(),

];
