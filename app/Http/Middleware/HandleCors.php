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
            
            if ($allowedOrigin) {
                $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            }
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');
            $response->headers->set('Access-Control-Expose-Headers', 'Authorization');
            
            return $response;
        }

        // Exécuter la requête normale
        $response = $next($request);

        // Ajouter les en-têtes CORS à la réponse (toujours, même si l'origine n'est pas dans la liste)
        // Cela permet de gérer les cas où l'origine n'est pas envoyée (requêtes same-origin)
        if ($allowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        } else {
            // Si pas d'origine spécifiée mais que c'est une requête API, autoriser quand même
            // (pour les requêtes same-origin ou sans en-tête Origin)
            $origin = $request->headers->get('Origin');
            if (!$origin && $request->is('api/*')) {
                // Pas d'origine = requête same-origin, pas besoin d'en-têtes CORS
            } elseif ($origin && !$allowedOrigin) {
                // Origine non autorisée, ne pas ajouter les en-têtes CORS
                // Le navigateur bloquera la requête de toute façon
            }
        }
        
        // Toujours ajouter les autres en-têtes CORS si une origine est autorisée
        if ($allowedOrigin) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Expose-Headers', 'Authorization');
        }

        return $response;
    }
}

