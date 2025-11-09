<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PasswordResetController extends Controller
{
    /**
     * Envoyer un lien de réinitialisation de mot de passe
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email n\'est pas valide.',
            'email.exists' => 'Aucun compte n\'est associé à cette adresse email.',
        ]);

        $email = $request->email;

        // Générer un token unique
        $token = Str::random(64);

        // Supprimer les anciens tokens pour cet email
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();

        // Créer un nouveau token
        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        // Récupérer l'utilisateur
        $user = User::where('email', $email)->first();

        // Envoyer l'email avec le lien de réinitialisation
        try {
            Mail::to($email)->send(new \App\Mail\ResetPassword($user, $token));
        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'envoi de l\'email de réinitialisation: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'email. Veuillez réessayer.',
            ], 500);
        }

        return response()->json([
            'message' => 'Un lien de réinitialisation a été envoyé à votre adresse email.',
        ]);
    }

    /**
     * Réinitialiser le mot de passe
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email n\'est pas valide.',
            'email.exists' => 'Aucun compte n\'est associé à cette adresse email.',
            'token.required' => 'Le token de réinitialisation est requis.',
            'password.required' => 'Le mot de passe est requis.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ]);

        $email = $request->email;
        $token = $request->token;
        $password = $request->password;

        // Vérifier si le token existe et n'est pas expiré (24h)
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'message' => 'Token de réinitialisation invalide ou expiré.',
            ], 400);
        }

        // Vérifier si le token est expiré (24h)
        $createdAt = Carbon::parse($resetRecord->created_at);
        if ($createdAt->addHours(24)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'message' => 'Le lien de réinitialisation a expiré. Veuillez en demander un nouveau.',
            ], 400);
        }

        // Vérifier le token
        if (!Hash::check($token, $resetRecord->token)) {
            return response()->json([
                'message' => 'Token de réinitialisation invalide.',
            ], 400);
        }

        // Mettre à jour le mot de passe de l'utilisateur
        $user = User::where('email', $email)->first();
        $user->password = Hash::make($password);
        $user->save();

        // Supprimer le token utilisé
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé avec succès.',
        ]);
    }

    /**
     * Vérifier la validité d'un token de réinitialisation
     */
    public function verifyToken(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
        ]);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'valid' => false,
                'message' => 'Token invalide ou expiré.',
            ], 400);
        }

        // Vérifier si le token est expiré (24h)
        $createdAt = Carbon::parse($resetRecord->created_at);
        if ($createdAt->addHours(24)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'valid' => false,
                'message' => 'Le lien de réinitialisation a expiré.',
            ], 400);
        }

        // Vérifier le token
        if (!Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'valid' => false,
                'message' => 'Token invalide.',
            ], 400);
        }

        return response()->json([
            'valid' => true,
            'message' => 'Token valide.',
        ]);
    }
}
