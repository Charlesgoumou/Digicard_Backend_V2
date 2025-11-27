<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

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
        // ✅ CRITIQUE PRODUCTION: Forcer HTTPS en production derrière Cloudflare
        // Cela garantit que toutes les URLs générées utilisent HTTPS et que les cookies Secure fonctionnent
        // Nécessaire car trustProxies permet de détecter HTTPS, mais forceScheme garantit que les URLs générées sont en HTTPS
        // et que Laravel sait qu'il est en HTTPS pour les cookies Secure
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Définition du Gate pour l'accès au dashboard admin
        Gate::define('viewAdminDashboard', function ($user) {
            return $user->is_admin === true;
        });

        // Alias middleware: vérifier que l'utilisateur n'est pas suspendu
        Route::aliasMiddleware('not_suspended', \App\Http\Middleware\EnsureUserNotSuspended::class);
    }
}
