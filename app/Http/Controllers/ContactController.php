<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactMessage;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    /**
     * Envoyer un message de contact par email
     */
    public function sendMessage(Request $request)
    {
        // Validation des données
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        try {
            // Envoyer l'email à contact@arccenciel.com
            Mail::to('contact@arccenciel.com')->send(new ContactMessage(
                $validatedData['name'],
                $validatedData['email'],
                $validatedData['subject'],
                $validatedData['message']
            ));

            return response()->json([
                'message' => 'Votre message a été envoyé avec succès ! Nous vous répondrons dans les plus brefs délais.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi du message de contact: ' . $e->getMessage());

            return response()->json([
                'message' => 'Une erreur est survenue lors de l\'envoi de votre message. Veuillez réessayer ultérieurement.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
