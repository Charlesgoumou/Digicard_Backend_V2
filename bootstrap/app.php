<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureFrontendRequestsAreStateful;
use App\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api', // Préfixe /api pour être compatible avec le frontend
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Ajouter le middleware CORS en premier pour toutes les requêtes API
        // Cela garantit que toutes les routes API reçoivent les en-têtes CORS
        $middleware->api(prepend: [
            HandleCors::class,
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
