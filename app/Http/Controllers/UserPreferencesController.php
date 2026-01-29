<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserPreferencesController extends Controller
{
    /**
     * Liste blanche des clés autorisées dans les préférences.
     * SÉCURITÉ: Seules ces clés peuvent être stockées. Aucune donnée sensible n'est autorisée.
     */
    private const ALLOWED_KEYS = [
        'theme',              // 'light' | 'dark' | 'auto'
        'sidebar_state',      // boolean (ouvert/fermé)
        'language',           // 'fr' | 'en' | etc.
        'notifications',      // object avec des sous-clés booléennes
        'dashboard_layout',   // string (layout preference)
        'table_pagination',   // number (items per page)
    ];

    /**
     * Récupère les préférences de l'utilisateur connecté.
     * Si l'utilisateur n'a pas de préférences en BDD, retourne un objet vide.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $preferences = $user->preferences ?? [];
        
        return response()->json([
            'preferences' => $preferences,
        ]);
    }

    /**
     * Met à jour les préférences de l'utilisateur connecté.
     * Valide strictement les données entrantes pour éviter l'injection de données non autorisées.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Valider que la requête contient un objet JSON
        $validator = Validator::make($request->all(), [
            'preferences' => 'required|array',
            'preferences.theme' => 'nullable|string|in:light,dark,auto',
            'preferences.sidebar_state' => 'nullable|boolean',
            'preferences.language' => 'nullable|string|max:10',
            'preferences.notifications' => 'nullable|array',
            'preferences.dashboard_layout' => 'nullable|string|max:50',
            'preferences.table_pagination' => 'nullable|integer|min:5|max:100',
        ]);

        if ($validator->fails()) {
            Log::warning('UserPreferencesController: Validation failed', [
                'user_id' => $user->id,
                'errors' => $validator->errors()->toArray(),
                'input' => $request->input('preferences'),
            ]);
            
            return response()->json([
                'message' => 'Les données de préférences sont invalides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $inputPreferences = $request->input('preferences', []);
        
        // SÉCURITÉ CRITIQUE: Filtrer uniquement les clés autorisées
        $sanitizedPreferences = [];
        foreach (self::ALLOWED_KEYS as $allowedKey) {
            if (array_key_exists($allowedKey, $inputPreferences)) {
                $sanitizedPreferences[$allowedKey] = $inputPreferences[$allowedKey];
            }
        }

        // Fusionner avec les préférences existantes (priorité aux nouvelles valeurs)
        $existingPreferences = $user->preferences ?? [];
        $mergedPreferences = array_merge($existingPreferences, $sanitizedPreferences);

        // Valider les types de données pour chaque clé
        $validatedPreferences = $this->validatePreferenceTypes($mergedPreferences);

        // Mettre à jour l'utilisateur
        try {
            $user->preferences = $validatedPreferences;
            $user->save();

            Log::info('UserPreferencesController: Preferences updated', [
                'user_id' => $user->id,
                'preferences_keys' => array_keys($validatedPreferences),
            ]);

            return response()->json([
                'message' => 'Préférences mises à jour avec succès.',
                'preferences' => $validatedPreferences,
            ]);
        } catch (\Exception $e) {
            Log::error('UserPreferencesController: Error updating preferences', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de la mise à jour des préférences.',
            ], 500);
        }
    }

    /**
     * Valide et nettoie les types de données pour chaque préférence.
     * S'assure que les valeurs correspondent aux types attendus.
     */
    private function validatePreferenceTypes(array $preferences): array
    {
        $validated = [];

        // Theme: doit être 'light', 'dark', ou 'auto'
        if (isset($preferences['theme'])) {
            $theme = $preferences['theme'];
            if (in_array($theme, ['light', 'dark', 'auto'], true)) {
                $validated['theme'] = $theme;
            }
        }

        // Sidebar state: doit être un booléen
        if (isset($preferences['sidebar_state'])) {
            $validated['sidebar_state'] = (bool) $preferences['sidebar_state'];
        }

        // Language: doit être une chaîne de caractères
        if (isset($preferences['language'])) {
            $validated['language'] = (string) $preferences['language'];
        }

        // Notifications: doit être un tableau associatif
        if (isset($preferences['notifications']) && is_array($preferences['notifications'])) {
            $validated['notifications'] = array_map(function ($value) {
                return (bool) $value;
            }, $preferences['notifications']);
        }

        // Dashboard layout: doit être une chaîne de caractères
        if (isset($preferences['dashboard_layout'])) {
            $validated['dashboard_layout'] = (string) $preferences['dashboard_layout'];
        }

        // Table pagination: doit être un entier entre 5 et 100
        if (isset($preferences['table_pagination'])) {
            $pagination = (int) $preferences['table_pagination'];
            if ($pagination >= 5 && $pagination <= 100) {
                $validated['table_pagination'] = $pagination;
            }
        }

        return $validated;
    }
}
