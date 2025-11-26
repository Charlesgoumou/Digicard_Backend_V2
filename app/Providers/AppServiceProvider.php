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
        // Cela garantit que toutes les URLs générées utilisent HTTPS
        // Nécessaire car trustProxies permet de détecter HTTPS, mais forceScheme garantit que les URLs générées sont en HTTPS
        if (config('app.env') === 'production' && config('app.url') && str_starts_with(config('app.url'), 'https://')) {
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
