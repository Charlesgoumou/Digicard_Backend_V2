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
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // 1. ✅ SECURITÉ : Désactiver la protection CSRF pour les Webhooks de paiement
        // C'est la partie manquante qui bloque ChapChap
        $middleware->validateCsrfTokens(except: [
            'api/payment/webhook',
            'api/payment/callback', // Au cas où
            'payment/*',           // Sécurité supplémentaire
        ]);

        // 2. Proxies (Votre config existante - Ne pas toucher)
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // 3. API Middleware (Votre config existante - Ne pas toucher)
        $middleware->api(prepend: [
            HandleCors::class,
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // 4. Web Middleware (Votre config existante - Ne pas toucher)
        $middleware->web(append: [
            HandleCors::class,
        ]);

        // 5. Config API & Alias (Votre config existante - Ne pas toucher)
        $middleware->group('api', [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ...
    })
    ->booting(function (Application $app) {
         RateLimiter::for('api', function (Request $request) {
             return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
         });
     })
    ->create();
