<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::define('manage-users', function (User $user) {
            return $user->is_admin;
        });

        Gate::define('edit-project-files', function (User $user, $projectId){
            if($user->is_admin){
                return true;
            }

            return $user->projects()->where('projects.id', $projectId)->exists();

        });
    }
}
