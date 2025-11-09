<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Liste des origines autorisées
        $allowedOrigins = [
            'http://localhost:5173',
            'http://192.168.1.126:5173',
            'https://digicard.arccenciel.com',
            'https://admin.digicard.arccenciel.com',
        ];

        // Vérifier si l'origine correspond à un pattern
        $origin = $request->headers->get('Origin');
        $allowedOrigin = null;

        if ($origin) {
            // Vérifier les origines exactes
            if (in_array($origin, $allowedOrigins)) {
                $allowedOrigin = $origin;
            } else {
                // Vérifier les patterns
                $patterns = [
                    '/^https?:\/\/.*\.arccenciel\.com$/',
                    '/^https?:\/\/.*\.digicard\.arccenciel\.com$/',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $origin)) {
                        $allowedOrigin = $origin;
                        break;
                    }
                }
            }
        }

        // Gérer les requêtes OPTIONS (preflight)
        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 200);
            
            // Toujours ajouter les en-têtes CORS pour les requêtes OPTIONS
            // Si l'origine n'est pas autorisée, le navigateur bloquera la requête de toute façon
            if ($allowedOrigin) {
                $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Max-Age', '86400');
                $response->headers->set('Access-Control-Expose-Headers', 'Authorization');
            }
            
            return $response;
        }

        // Exécuter la requête normale
        $response = $next($request);

        // IMPORTANT: Toujours ajouter les en-têtes CORS à la réponse si une origine est autorisée
        // C'est crucial pour que les requêtes POST/PUT/DELETE fonctionnent après le preflight
        if ($allowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Expose-Headers', 'Authorization');
            
            // Log pour le débogage (à retirer en production)
            // \Log::info('CORS headers added', ['origin' => $origin, 'allowedOrigin' => $allowedOrigin, 'path' => $request->path()]);
        } else if ($origin) {
            // Si une origine est présente mais non autorisée, logger pour le débogage
            // \Log::warning('CORS: Origin not allowed', ['origin' => $origin, 'path' => $request->path()]);
        }

        return $response;
    }
}

