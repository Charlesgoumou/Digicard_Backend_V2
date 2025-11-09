<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Définition du Gate pour l'accès au dashboard admin
        Gate::define('viewAdminDashboard', function ($user) {
            return $user->is_admin === true;
        });

        // Alias middleware: vérifier que l'utilisateur n'est pas suspendu
        Route::aliasMiddleware('not_suspended', \App\Http\Middleware\EnsureUserNotSuspended::class);
    }
}
