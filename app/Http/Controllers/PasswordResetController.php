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
        \Log::info('Password reset link request received', [
            'email' => $request->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // ✅ CORRECTION: Valider seulement le format de l'email, pas son existence
        // Pour des raisons de sécurité, on ne doit pas révéler si un email existe ou non
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email n\'est pas valide.',
        ]);

        $email = $request->email;

        // ✅ CORRECTION: Vérifier si l'utilisateur existe
        $user = User::where('email', $email)->first();

        // ✅ SÉCURITÉ: Toujours retourner le même message, même si l'email n'existe pas
        // Cela évite de révéler quels emails sont enregistrés dans la base de données
        if (!$user) {
            \Log::info('Password reset requested for non-existent email', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);
            // Retourner le même message de succès pour ne pas révéler que l'email n'existe pas
            return response()->json([
                'message' => 'Si cet email existe dans notre système, un lien de réinitialisation a été envoyé.',
                'success' => true,
            ], 200);
        }

        // ✅ CORRECTION: Générer un token unique
        $token = Str::random(64);

        // ✅ IMPORTANT: Supprimer les anciens tokens pour cet email AVANT d'en créer un nouveau
        // Cela invalide automatiquement les anciens liens de réinitialisation
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();

        // Créer un nouveau token
        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        // ✅ CORRECTION: Envoyer l'email avec le lien de réinitialisation
        // Améliorer la gestion des erreurs pour mieux diagnostiquer les problèmes en production
        try {
            Mail::to($email)->send(new \App\Mail\ResetPassword($user, $token));
            \Log::info('Password reset email sent successfully', [
                'email' => $email,
                'user_id' => $user->id,
                'mailer' => config('mail.default'),
                'mail_from' => config('mail.from.address'),
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'envoi de l\'email de réinitialisation', [
                'email' => $email,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'mailer' => config('mail.default'),
                'mail_from' => config('mail.from.address'),
                'mail_host' => config('mail.mailers.smtp.host'),
            ]);
            // ✅ CORRECTION: Retourner une erreur plus détaillée pour le débogage
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'email. Veuillez réessayer plus tard.',
                'error' => app()->environment('local', 'development') ? $e->getMessage() : null,
            ], 500);
        }

        \Log::info('Password reset link response sent', [
            'email' => $email,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Un lien de réinitialisation a été envoyé à votre adresse email.',
            'success' => true,
        ], 200);
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
