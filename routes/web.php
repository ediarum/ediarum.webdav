<?php

use App\Http\Controllers\WebDavController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/


$verbs = [
    'GET',
    'HEAD',
    'POST',
    'PUT',
    'PATCH',
    'DELETE',
    'PROPFIND',
    'PROPPATCH',
    'MKCOL',
    'COPY',
    'MOVE',
    'LOCK',
    'UNLOCK',
    'OPTIONS',
    'REPORT',
];

Router::$verbs = array_merge(Router::$verbs, $verbs);

if (env('APP_ENV') == 'production') {
    \Illuminate\Support\Facades\URL::forceScheme("https");
}

Route::any('/connection/{projectSlug}/{path?}', WebDavController::class)
    ->where('path', '(.)*')
    ->middleware(['webdav.throttle', 'auth.basic', 'webdav.proxy.propfind', 'webdav.proxy.destination']);
