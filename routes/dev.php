<?php

use App\Http\Controllers\DevStandaloneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Standalone admin frontend (local development only)
|--------------------------------------------------------------------------
|
| Every route here is a 404 unless APP_ENV=local; see DevStandaloneController.
|
*/

Route::get('/', [DevStandaloneController::class, 'root'])->name('dev.root');
Route::get('/webdav/{path?}', [DevStandaloneController::class, 'page'])
    ->where('path', '.*')
    ->name('dev.standalone');
