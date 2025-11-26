<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class StorageController extends Controller
{
    /**
     * Servir les fichiers depuis storage/app/public
     */
    public function serve(Request $request, string $path): Response
    {
        try {
            // Log pour déboguer
            Log::info('=== STORAGE CONTROLLER CALLED ===', [
                'path' => $path,
                'request_uri' => $request->getRequestUri(),
                'method' => $request->method(),
                'full_path' => storage_path('app/public/' . $path),
            ]);
            
            // Nettoyer le chemin (enlever les slashes en début/fin)
            $path = ltrim($path, '/');
            
            // ✅ CORRECTION : Gérer le cas où le chemin commence par "api/storage/" (route API)
            // Si le chemin commence par "api/storage/", le retirer
            if (str_starts_with($path, 'api/storage/')) {
                $path = substr($path, strlen('api/storage/'));
            }
            
            // Vérifier que le fichier existe
            if (!Storage::disk('public')->exists($path)) {
                Log::warning('Storage file not found', [
                    'path' => $path,
                    'full_path' => storage_path('app/public/' . $path),
                    'exists' => file_exists(storage_path('app/public/' . $path)),
                    'storage_dir_exists' => is_dir(storage_path('app/public')),
                    'files_in_dir' => Storage::disk('public')->files(dirname($path)),
                ]);
                abort(404, 'Fichier non trouvé: ' . $path);
            }
            
            // Récupérer le fichier
            $file = Storage::disk('public')->get($path);
            $type = Storage::disk('public')->mimeType($path);
            
            // Si le type MIME n'est pas détecté, essayer de le deviner depuis l'extension
            if (!$type) {
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'pdf' => 'application/pdf',
                ];
                $type = $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
            }
            
            // ✅ OPTIMISATION CACHE: Récupérer les métadonnées du fichier pour ETag et Last-Modified
            $lastModified = Storage::disk('public')->lastModified($path);
            $fileSize = Storage::disk('public')->size($path);
            
            // ✅ OPTIMISATION CACHE: Générer un ETag basé sur le chemin et la date de modification
            // Cela permet au navigateur de faire des requêtes conditionnelles (304 Not Modified)
            $etag = md5($path . $lastModified . $fileSize);
            
            // ✅ OPTIMISATION CACHE: Vérifier si le client a déjà la version en cache (requête conditionnelle)
            $ifNoneMatch = $request->header('If-None-Match');
            $ifModifiedSince = $request->header('If-Modified-Since');
            
            if ($ifNoneMatch === $etag || ($ifModifiedSince && $lastModified <= strtotime($ifModifiedSince))) {
                // Le client a déjà la version en cache, retourner 304 Not Modified
                Log::info('Storage file not modified (304)', [
                    'path' => $path,
                    'etag' => $etag,
                ]);
                
                return response('', 304, [
                    'ETag' => $etag,
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                    'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                ]);
            }
            
            Log::info('Storage file served successfully', [
                'path' => $path,
                'type' => $type,
                'size' => strlen($file),
                'etag' => $etag,
            ]);
            
            // Obtenir l'origine de la requête pour CORS
            $origin = $request->headers->get('Origin');
            $allowedOrigins = [
                'http://localhost:5173',
                'http://localhost:3000',
                'http://localhost:8000',
                'https://digicard.arccenciel.com',
                'https://admin.digicard.arccenciel.com',
            ];
            
            // Vérifier si l'origine est autorisée
            $corsHeaders = [];
            if ($origin && (in_array($origin, $allowedOrigins) || preg_match('/^https?:\/\/.*\.arccenciel\.com$/', $origin))) {
                $corsHeaders['Access-Control-Allow-Origin'] = $origin;
                $corsHeaders['Access-Control-Allow-Methods'] = 'GET, HEAD, OPTIONS';
                $corsHeaders['Access-Control-Allow-Headers'] = 'Origin, X-Requested-With, Content-Type, Accept, Authorization';
                $corsHeaders['Access-Control-Allow-Credentials'] = 'true';
            }
            
            // ✅ OPTIMISATION CACHE: En-têtes de cache optimisés pour les images
            // - public: Le fichier peut être mis en cache par les CDN et les navigateurs
            // - max-age=31536000: Cache pendant 1 an (31536000 secondes)
            // - immutable: Indique que le fichier ne changera jamais (optimisation navigateur)
            // - ETag: Permet la validation conditionnelle (304 Not Modified)
            // - Last-Modified: Permet la validation conditionnelle basée sur la date
            return response($file, 200, array_merge([
                'Content-Type' => $type,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'ETag' => $etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                'Content-Length' => strlen($file),
            ], $corsHeaders));
        } catch (\Exception $e) {
            Log::error('Error serving storage file', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            abort(500, 'Erreur lors du chargement du fichier: ' . $e->getMessage());
        }
    }
}

