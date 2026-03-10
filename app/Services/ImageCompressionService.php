<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Exception;

class ImageCompressionService
{
    private $maxFileSize = 400 * 1024; // 400KB en bytes
    private $quality = 85; // Qualité de compression initiale
    private $manager;

    public function __construct()
    {
        // Utiliser GD driver (généralement disponible par défaut)
        try {
            $this->manager = new ImageManager(new Driver());
        } catch (Exception $e) {
            \Log::error('Erreur lors de l\'initialisation d\'ImageManager: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Compresse une image pour qu'elle soit inférieure à 400KB
     * 
     * @param UploadedFile $file Le fichier à compresser
     * @param string $path Le chemin de destination (optionnel)
     * @return array ['path' => string, 'size' => int, 'compressed' => bool]
     */
    public function compressImage(UploadedFile $file, string $directory = 'compressed'): array
    {
        try {
            // Vérifier si c'est une image
            if (!$this->isImage($file)) {
                throw new Exception('Le fichier n\'est pas une image valide.');
            }

            // Si le fichier est déjà inférieur à 400KB, le retourner tel quel
            if ($file->getSize() <= $this->maxFileSize) {
                $path = $file->store($directory, 'public');
                return [
                    'path' => $path,
                    'size' => $file->getSize(),
                    'compressed' => false,
                ];
            }

            // Charger l'image avec Intervention Image
            $image = $this->manager->read($file->getRealPath());
            
            // Obtenir les dimensions originales
            $originalWidth = $image->width();
            $originalHeight = $image->height();
            
            // Déterminer les dimensions maximales (1920x1920 pour les grandes images)
            $maxWidth = 1920;
            $maxHeight = 1920;
            
            // Redimensionner si nécessaire (scaleDown retourne une nouvelle instance)
            if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
                $image = $image->scaleDown($maxWidth, $maxHeight);
                // Mettre à jour les dimensions après redimensionnement
                $currentWidth = $image->width();
                $currentHeight = $image->height();
            } else {
                $currentWidth = $originalWidth;
                $currentHeight = $originalHeight;
            }

            // Convertir en JPEG pour une meilleure compression (sauf pour les PNG transparents)
            $mimeType = $file->getMimeType();
            $shouldConvertToJpeg = in_array($mimeType, ['image/png', 'image/gif']) && !$this->hasTransparency($file);

            // Générer un nom de fichier unique
            $finalExtension = $shouldConvertToJpeg ? 'jpg' : $file->getClientOriginalExtension();
            $filename = uniqid() . '.' . $finalExtension;
            $tempPath = sys_get_temp_dir() . '/' . $filename;

            // Essayer différentes qualités jusqu'à obtenir une taille < 400KB
            $quality = $this->quality;
            $compressed = false;
            $attempts = 0;
            $maxAttempts = 10;
            $scaleFactor = 1.0;

            while ($attempts < $maxAttempts && !$compressed) {
                try {
                    // Nettoyer le fichier temporaire précédent si il existe
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }

                    // Déterminer le format et la qualité
                    $useJpeg = $shouldConvertToJpeg || $attempts >= 3;
                    $currentQuality = $useJpeg ? max(50, $quality) : null;

                    // Créer une image à la bonne taille
                    $workingImage = $image;
                    if ($scaleFactor < 1.0) {
                        $newWidth = (int)($currentWidth * $scaleFactor);
                        $newHeight = (int)($currentHeight * $scaleFactor);
                        $workingImage = $image->scale($newWidth, $newHeight);
                    }

                    // Enregistrer l'image
                    if ($useJpeg) {
                        $workingImage->toJpeg($currentQuality)->save($tempPath);
                        $finalExtension = 'jpg';
                    } else {
                        $workingImage->toPng()->save($tempPath);
                        $finalExtension = 'png';
                    }

                    if (!file_exists($tempPath)) {
                        throw new Exception('Impossible de créer le fichier temporaire');
                    }

                    $fileSize = filesize($tempPath);

                    // Si la taille est acceptable, arrêter
                    if ($fileSize <= $this->maxFileSize) {
                        $compressed = true;
                        $filename = uniqid() . '.' . $finalExtension;
                        // Renommer le fichier temporaire si nécessaire
                        $newTempPath = sys_get_temp_dir() . '/' . $filename;
                        if ($tempPath !== $newTempPath) {
                            rename($tempPath, $newTempPath);
                            $tempPath = $newTempPath;
                        }
                        break;
                    }

                    // Réduire la qualité ou la taille pour la prochaine tentative
                    if ($useJpeg) {
                        $quality -= 8;
                        if ($quality < 55) {
                            // Si la qualité est trop basse, réduire la taille de l'image
                            $scaleFactor *= 0.85;
                            $quality = 75; // Réinitialiser la qualité
                        }
                    } else {
                        // Pour PNG, réduire la taille de l'image
                        $scaleFactor *= 0.85;
                    }

                    $attempts++;
                } catch (Exception $e) {
                    \Log::error('Erreur lors de la compression (tentative ' . $attempts . '): ' . $e->getMessage());
                    // Si on ne peut pas sauvegarder, réduire la taille et forcer JPEG
                    $scaleFactor *= 0.8;
                    $quality = 70;
                    $shouldConvertToJpeg = true; // Forcer JPEG
                    $attempts++;
                    if ($attempts >= $maxAttempts) {
                        throw new Exception('Impossible de compresser l\'image après ' . $attempts . ' tentatives: ' . $e->getMessage());
                    }
                }
            }

            // Si on n'a toujours pas réussi, forcer une compression maximale en JPEG
            if (!$compressed) {
                try {
                    // Nettoyer le fichier temporaire précédent
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                    
                    // Réduire encore plus la taille et forcer JPEG avec qualité minimale
                    $finalImage = $image->scale((int)($currentWidth * 0.6), (int)($currentHeight * 0.6));
                    $filename = uniqid() . '.jpg';
                    $tempPath = sys_get_temp_dir() . '/' . $filename;
                    $finalImage->toJpeg(60)->save($tempPath);
                    
                    // Si toujours trop grand, dernière tentative avec qualité très basse
                    if (file_exists($tempPath) && filesize($tempPath) > $this->maxFileSize) {
                        $finalImage = $image->scale((int)($currentWidth * 0.5), (int)($currentHeight * 0.5));
                        $finalImage->toJpeg(50)->save($tempPath);
                    }
                    
                    $compressed = true;
                } catch (Exception $e) {
                    \Log::error('Erreur lors de la compression finale: ' . $e->getMessage());
                    throw $e;
                }
            }

            // Stocker le fichier compressé
            $storagePath = Storage::disk('public')->putFileAs(
                $directory,
                new \Illuminate\Http\File($tempPath),
                $filename
            );

            // Nettoyer le fichier temporaire
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return [
                'path' => $storagePath,
                'size' => Storage::disk('public')->size($storagePath),
                'compressed' => true,
            ];

        } catch (Exception $e) {
            \Log::error('Erreur lors de la compression d\'image: ' . $e->getMessage());
            
            // En cas d'erreur, retourner le fichier original
            $path = $file->store($directory, 'public');
            return [
                'path' => $path,
                'size' => $file->getSize(),
                'compressed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifie si le fichier est une image
     */
    private function isImage(UploadedFile $file): bool
    {
        $mimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        return in_array($file->getMimeType(), $mimeTypes);
    }

    /**
     * Vérifie si une image PNG a de la transparence (méthode optimisée)
     */
    private function hasTransparency(UploadedFile $file): bool
    {
        if ($file->getMimeType() !== 'image/png') {
            return false;
        }

        try {
            // Lire les premiers bytes pour vérifier la présence d'un canal alpha
            $handle = fopen($file->getRealPath(), 'rb');
            if (!$handle) {
                return false;
            }

            // Lire le header PNG (les 24 premiers bytes)
            $header = fread($handle, 24);
            fclose($handle);

            // Vérifier si c'est un PNG valide (signature PNG)
            if (substr($header, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return false;
            }

            // Vérifier le type de couleur (byte 25 = type de couleur)
            // 4 = GrayScale + Alpha, 6 = RGB + Alpha
            $colorType = ord($header[25]);
            
            // Si le type de couleur indique un canal alpha, l'image a de la transparence
            return ($colorType === 4 || $colorType === 6);
        } catch (Exception $e) {
            // En cas d'erreur, supposer qu'il n'y a pas de transparence pour éviter les problèmes
            return false;
        }
    }
}

