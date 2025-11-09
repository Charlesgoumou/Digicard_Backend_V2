<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Mail\VerificationCodeMail;
use App\Mail\PasswordChangedNotification;

class AccountController extends Controller
{
    /**
     * Met à jour les informations de compte utilisateur (nom, email, téléphone, mot de passe).
     * Pour le changement d'email, envoie un code de vérification à la nouvelle adresse.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        // Validation des données
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users')->ignore($user->id)
            ],
            'phone' => 'nullable|string|regex:/^\+224[0-9]{9}$/',
            'current_password' => 'required_with:password',
            'password' => 'required_with:current_password|min:8|confirmed',
        ]);

        // Si changement d'email
        if ($request->has('email') && $request->email !== $user->email) {
            $newEmail = $request->email;
            
            // Vérifier que le nouvel email n'est pas déjà utilisé
            $existingUser = \App\Models\User::where('email', $newEmail)
                ->where('id', '!=', $user->id)
                ->first();
            
            if ($existingUser) {
                return response()->json([
                    'message' => 'Cette adresse email est déjà utilisée.',
                    'errors' => ['email' => ['Cette adresse email est déjà utilisée.']]
                ], 422);
            }

            // Générer un code de vérification
            $code = rand(100000, 999999);
            
            // Stocker le nouvel email et le code dans la base de données
            $user->pending_email = $newEmail;
            $user->email_change_code = (string)$code;
            $user->email_change_code_expires_at = now()->addMinutes(15);
            $user->save();

            // Envoyer le code de vérification à la nouvelle adresse email
            try {
                Mail::to($newEmail)->send(new VerificationCodeMail($code));
            } catch (\Exception $e) {
                Log::error('Échec de l\'envoi de l\'email de vérification à ' . $newEmail . ': ' . $e->getMessage());
                return response()->json([
                    'message' => 'Erreur lors de l\'envoi de l\'email de vérification. Veuillez réessayer.',
                ], 500);
            }

            // Ne pas mettre à jour l'email immédiatement - attendre la vérification
            unset($validated['email']);
            
            return response()->json([
                'message' => 'Un code de vérification a été envoyé à votre nouvelle adresse email. Veuillez le saisir pour confirmer le changement.',
                'requires_verification' => true,
                'pending_email' => $newEmail,
                'user' => $user->fresh()
            ]);
        }

        // Si le téléphone est fourni, mettre à jour aussi vcard_phone et phone_numbers
        if ($request->has('phone')) {
            $phone = $request->phone;
            if ($phone === null || $phone === '') {
                // Permettre de supprimer le téléphone
                $validated['vcard_phone'] = null;
                // Garder phone_numbers tel quel ou le vider
                $phoneNumbers = $user->phone_numbers ?? [];
                if (!empty($phoneNumbers) && is_array($phoneNumbers)) {
                    // Retirer le premier élément (téléphone principal) s'il existe
                    array_shift($phoneNumbers);
                    $validated['phone_numbers'] = array_values($phoneNumbers);
                } else {
                    $validated['phone_numbers'] = [];
                }
            } else {
                // Mettre à jour avec le nouveau téléphone
                $validated['vcard_phone'] = $phone;
                // Mettre à jour phone_numbers avec le nouveau téléphone en premier
                $phoneNumbers = $user->phone_numbers ?? [];
                if (!empty($phoneNumbers) && is_array($phoneNumbers)) {
                    // Retirer l'ancien téléphone s'il existe et ajouter le nouveau en premier
                    $phoneNumbers = array_values(array_filter($phoneNumbers, function($p) use ($phone) {
                        return $p !== $phone;
                    }));
                    array_unshift($phoneNumbers, $phone);
                } else {
                    $phoneNumbers = [$phone];
                }
                $validated['phone_numbers'] = $phoneNumbers;
            }
            unset($validated['phone']); // Ne pas stocker 'phone' directement
        }

        // Vérifier le mot de passe actuel si changement de mot de passe
        $passwordChanged = false;
        if ($request->has('current_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'message' => 'Le mot de passe actuel est incorrect.'
                ], 400);
            }
            $validated['password'] = Hash::make($validated['password']);
            // Supprimer les champs de validation qui ne doivent pas être stockés
            unset($validated['current_password']);
            unset($validated['password_confirmation']);
            $passwordChanged = true;
        }

        // Mettre à jour uniquement les champs fournis
        $user->fill($validated);
        $user->save();

        // Envoyer un email de notification si le mot de passe a été changé
        if ($passwordChanged) {
            try {
                Mail::to($user->email)->send(new PasswordChangedNotification($user));
            } catch (\Exception $e) {
                Log::error('Échec de l\'envoi de l\'email de notification de changement de mot de passe à ' . $user->email . ': ' . $e->getMessage());
                // Ne pas bloquer la réponse même si l'email échoue
            }
        }

        return response()->json([
            'message' => 'Informations mises à jour avec succès',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Vérifie le code de changement d'email et finalise le changement.
     */
    public function verifyEmailChange(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        // Vérifier que l'utilisateur a un changement d'email en attente
        if (!$user->pending_email || !$user->email_change_code) {
            return response()->json([
                'message' => 'Aucun changement d\'email en attente.',
            ], 400);
        }

        // Vérifier que le code n'a pas expiré
        if (!$user->email_change_code_expires_at || now()->gt($user->email_change_code_expires_at)) {
            // Nettoyer les champs
            $user->pending_email = null;
            $user->email_change_code = null;
            $user->email_change_code_expires_at = null;
            $user->save();

            return response()->json([
                'message' => 'Le code de vérification a expiré. Veuillez recommencer le processus de changement d\'email.',
            ], 400);
        }

        // Vérifier le code
        if ($user->email_change_code !== $validated['code']) {
            return response()->json([
                'message' => 'Code de vérification incorrect.',
                'errors' => ['code' => ['Le code de vérification est incorrect.']]
            ], 422);
        }

        // Le code est valide, finaliser le changement d'email
        $newEmail = $user->pending_email;
        $user->email = $newEmail;
        $user->pending_email = null;
        $user->email_change_code = null;
        $user->email_change_code_expires_at = null;
        $user->email_verified_at = null; // Réinitialiser la vérification d'email
        $user->save();

        return response()->json([
            'message' => 'Adresse email mise à jour avec succès !',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Renvoie un nouveau code de vérification pour le changement d'email.
     */
    public function resendEmailChangeCode(Request $request)
    {
        $user = $request->user();

        // Vérifier que l'utilisateur a un changement d'email en attente
        if (!$user->pending_email) {
            return response()->json([
                'message' => 'Aucun changement d\'email en attente.',
            ], 400);
        }

        // Générer un nouveau code
        $code = rand(100000, 999999);
        $user->email_change_code = (string)$code;
        $user->email_change_code_expires_at = now()->addMinutes(15);
        $user->save();

        // Envoyer le code à la nouvelle adresse email
        try {
            Mail::to($user->pending_email)->send(new VerificationCodeMail($code));
        } catch (\Exception $e) {
            Log::error('Échec de l\'envoi de l\'email de vérification à ' . $user->pending_email . ': ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'email de vérification. Veuillez réessayer.',
            ], 500);
        }

        return response()->json([
            'message' => 'Un nouveau code de vérification a été envoyé à votre nouvelle adresse email.',
        ]);
    }
}
