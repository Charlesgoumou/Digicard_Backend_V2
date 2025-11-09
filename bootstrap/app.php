<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Configuration pour Sanctum SPA : ajouter EnsureFrontendRequestsAreStateful au groupe API
        // Cela gère automatiquement les cookies de session, CSRF et AuthenticateSession
        $middleware->api(prepend: [
             EnsureFrontendRequestsAreStateful::class,
        ]);

        // Configuration du groupe API
        $middleware->group('api', [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Enregistrement du middleware admin avec l'alias 'admin'
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ...
    })
    ->booting(function (Application $app) { // Configuration Rate Limiter (reste identique)
         RateLimiter::for('api', function (Request $request) {
             return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
         });
     })
    ->create();
