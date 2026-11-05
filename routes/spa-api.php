<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectGitController;
use App\Http\Controllers\ProjectLockedPathController;
use App\Http\Controllers\ProjectServicesController;
use App\Http\Controllers\ProjectUserController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Session API (SPA + ediarum.portal)
|--------------------------------------------------------------------------
|
| Loaded with the `web` middleware group (session cookie, XSRF-TOKEN check), unlike routes/api.php.
| /api/auth is the platform's auth provider (ediarum.platform docs/auth-contract.md); /api/webdav
| is the backend of this app's admin UI in the portal -- see planning/portal-integration.md.
|
*/

Route::prefix('api/auth')->name('auth.')->group(function () {
    Route::get('csrf-cookie', [AuthController::class, 'csrfCookie'])->name('csrf-cookie');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('verify', [AuthController::class, 'verify'])->name('verify');
    Route::get('service/projects', [ServiceController::class, 'projects'])
        ->middleware('auth.service')
        ->name('service.projects');

    Route::middleware('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('password', [AuthController::class, 'password'])->name('password');
        Route::post('profile', [AuthController::class, 'profile'])->name('profile');
        Route::get('projects', [AuthController::class, 'projects'])->name('projects');
    });
});

Route::prefix('api/webdav')->middleware(['auth', 'can:manage-users'])->group(function () {
    Route::get('ping', [AppController::class, 'ping'])->name('app.ping');
    Route::get('config', [AppController::class, 'config'])->name('app.config');

    Route::get('admin', [AdminController::class, 'index'])->name('admin.index');

    Route::get('users', [UsersController::class, 'index'])->name('users.index');
    Route::post('users', [UsersController::class, 'store'])->name('users.store');
    Route::put('users/reset-password/{id}', [UsersController::class, 'resetPassword'])
        ->name('users.reset-password');

    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show', 'update']);
    Route::get('project/{id}/initialize/stream', [ProjectController::class, 'initializeStream'])
        ->name('projects.initialize-stream');
    Route::post('project-user', [ProjectUserController::class, 'attach'])->name('projects.add-user');
    Route::delete('project-user', [ProjectUserController::class, 'detach'])->name('projects.remove-user');
    Route::delete('project/{projectId}/lock/{lockId}', [ProjectController::class, 'removeLock'])->name('projects.remove-lock');

    Route::post('project/{projectId}/locked-path', [ProjectLockedPathController::class, 'store'])
        ->name('projects.lock-path');
    Route::delete('project/{projectId}/locked-path/{lockedPathId}', [ProjectLockedPathController::class, 'destroy'])
        ->name('projects.unlock-path');

    Route::get('project/{projectId}/push-to-existdb', [ProjectServicesController::class, 'pushFilesToExistDb'])
        ->name('projects.push-to-existdb');
    Route::post('project/{projectId}/failed-push/{uuid}/retry', [ProjectServicesController::class, 'retryFailedPush'])
        ->name('projects.retry-failed-push');
    Route::get('project/{projectId}/retry-failed-pushes', [ProjectServicesController::class, 'retryAllFailedPushes'])
        ->name('projects.retry-failed-pushes');
    Route::post('project/{projectId}/enable-maintenances-mode', [ProjectServicesController::class, 'enableMaintenanceMode'])
        ->name('projects.enable-maintenance-mode');
    Route::post('project/{projectId}/disable-maintenances-mode', [ProjectServicesController::class, 'disableMaintenanceMode'])
        ->name('projects.disable-maintenance-mode');

    Route::get('project/{projectId}/git-branches', [ProjectGitController::class, 'branches'])
        ->name('projects.git-branches');
    Route::get('project/{projectId}/git-branches/{branch}/status', [ProjectGitController::class, 'status'])
        ->where('branch', '.*')
        ->name('projects.git-branches.status');
    Route::post('project/{projectId}/git-branches/{branch}/merge', [ProjectGitController::class, 'merge'])
        ->where('branch', '.*')
        ->name('projects.git-branches.merge');
});
