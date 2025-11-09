<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UpdateIndividualOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update-individual {--dry-run : Afficher les modifications sans les appliquer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Met à jour les anciennes commandes des comptes particuliers (génère les access_token manquants, etc.)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Mise à jour des commandes individuelles...');
        $this->newLine();
        
        // Récupérer toutes les commandes individuelles
        // Les commandes individuelles sont celles dont l'utilisateur a le rôle 'individual'
        $orders = Order::join('users', 'orders.user_id', '=', 'users.id')
            ->where('users.role', 'individual')
            ->select('orders.*')
            ->with([
                'user' => function($query) {
                    $query->select([
                        'id', 'name', 'email', 'username', 'role', 'title', 
                        'avatar_url', 'whatsapp_url', 'linkedin_url', 'facebook_url',
                        'twitter_url', 'youtube_url', 'deezer_url', 'spotify_url',
                        'website_url', 'vcard_phone', 'phone_numbers', 'emails'
                    ]);
                }
            ])
            ->get();
        
        $this->info("Nombre total de commandes individuelles: {$orders->count()}");
        $this->newLine();
        
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        
        foreach ($orders as $order) {
            $updatedOrder = false;
            $changes = [];
            
            // Récupérer l'utilisateur (déjà chargé via with)
            $user = $order->user;
            
            if (!$user) {
                $this->warn("⚠ Commande #{$order->order_number} (ID: {$order->id}) - Utilisateur non trouvé");
                $skipped++;
                continue;
            }
            
            // 1. Vérifier et générer l'access_token si manquant pour les commandes validées
            if ($order->status === 'validated' && empty($order->access_token)) {
                $token = $order->generateAccessToken();
                $order->access_token = $token;
                $changes[] = "Access token généré: {$token}";
                $updatedOrder = true;
            }
            
            // 2. S'assurer que les données JSON (phone_numbers, emails) sont correctement formatées
            if ($order->phone_numbers && !is_array($order->phone_numbers)) {
                $phoneNumbers = is_string($order->phone_numbers) ? json_decode($order->phone_numbers, true) : null;
                if ($phoneNumbers !== null) {
                    $order->phone_numbers = $phoneNumbers;
                    $changes[] = "phone_numbers corrigé (JSON)";
                    $updatedOrder = true;
                }
            }
            
            if ($order->emails && !is_array($order->emails)) {
                $emails = is_string($order->emails) ? json_decode($order->emails, true) : null;
                if ($emails !== null) {
                    $order->emails = $emails;
                    $changes[] = "emails corrigé (JSON)";
                    $updatedOrder = true;
                }
            }
            
            // 3. Pour TOUTES les commandes configurées, copier les données depuis l'utilisateur si elles sont manquantes
            // Cela permet de mettre à jour les anciennes commandes avec les données configurées par l'utilisateur
            if ($order->is_configured && $user) {
                // Copier profile_name si manquant
                if (empty($order->profile_name)) {
                    $order->profile_name = $user->name;
                    $changes[] = "profile_name copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                // Copier profile_title si manquant (depuis user->title)
                if (empty($order->profile_title) && !empty($user->title)) {
                    $order->profile_title = $user->title;
                    $changes[] = "profile_title copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                // Copier order_avatar_url si manquant (depuis user->avatar_url)
                if (empty($order->order_avatar_url) && !empty($user->avatar_url)) {
                    $order->order_avatar_url = $user->avatar_url;
                    $changes[] = "order_avatar_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                // Copier les réseaux sociaux si manquants
                if (empty($order->whatsapp_url) && !empty($user->whatsapp_url)) {
                    $order->whatsapp_url = $user->whatsapp_url;
                    $changes[] = "whatsapp_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                if (empty($order->linkedin_url) && !empty($user->linkedin_url)) {
                    $order->linkedin_url = $user->linkedin_url;
                    $changes[] = "linkedin_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                if (empty($order->facebook_url) && !empty($user->facebook_url)) {
                    $order->facebook_url = $user->facebook_url;
                    $changes[] = "facebook_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                if (empty($order->twitter_url) && !empty($user->twitter_url)) {
                    $order->twitter_url = $user->twitter_url;
                    $changes[] = "twitter_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                if (empty($order->youtube_url) && !empty($user->youtube_url)) {
                    $order->youtube_url = $user->youtube_url;
                    $changes[] = "youtube_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                if (empty($order->deezer_url) && !empty($user->deezer_url)) {
                    $order->deezer_url = $user->deezer_url;
                    $changes[] = "deezer_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                if (empty($order->spotify_url) && !empty($user->spotify_url)) {
                    $order->spotify_url = $user->spotify_url;
                    $changes[] = "spotify_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                // Copier website_url si manquant
                if (empty($order->website_url) && !empty($user->website_url)) {
                    $order->website_url = $user->website_url;
                    $changes[] = "website_url copié depuis l'utilisateur";
                    $updatedOrder = true;
                }
                
                // Copier les données de contact si manquantes
                // Vérifier d'abord si phone_numbers est vide ou si c'est un tableau vide
                $orderPhoneNumbers = $order->phone_numbers;
                $isPhoneNumbersEmpty = empty($orderPhoneNumbers) || (is_array($orderPhoneNumbers) && count($orderPhoneNumbers) === 0);
                
                if ($isPhoneNumbersEmpty) {
                    // Essayer d'abord avec user->phone_numbers (tableau JSON)
                    if (!empty($user->phone_numbers) && is_array($user->phone_numbers) && count($user->phone_numbers) > 0) {
                        $order->phone_numbers = $user->phone_numbers;
                        $changes[] = "phone_numbers copié depuis l'utilisateur (tableau)";
                        $updatedOrder = true;
                    } elseif (!empty($user->vcard_phone)) {
                        // Sinon, utiliser vcard_phone
                        $order->phone_numbers = [$user->vcard_phone];
                        $changes[] = "phone_numbers copié depuis l'utilisateur (vcard_phone)";
                        $updatedOrder = true;
                    }
                }
                
                // Vérifier si emails est vide ou si c'est un tableau vide
                $orderEmails = $order->emails;
                $isEmailsEmpty = empty($orderEmails) || (is_array($orderEmails) && count($orderEmails) === 0);
                
                if ($isEmailsEmpty) {
                    // Essayer d'abord avec user->emails (tableau JSON)
                    if (!empty($user->emails) && is_array($user->emails) && count($user->emails) > 0) {
                        $order->emails = $user->emails;
                        $changes[] = "emails copié depuis l'utilisateur (tableau)";
                        $updatedOrder = true;
                    } elseif (!empty($user->email)) {
                        // Sinon, utiliser email
                        $order->emails = [$user->email];
                        $changes[] = "emails copié depuis l'utilisateur (email)";
                        $updatedOrder = true;
                    }
                }
                
                // Copier les couleurs par défaut si manquantes
                if (empty($order->profile_border_color)) {
                    $order->profile_border_color = '#facc15'; // Jaune par défaut
                    $changes[] = "profile_border_color initialisé (couleur par défaut)";
                    $updatedOrder = true;
                }
                
                if (empty($order->save_contact_button_color)) {
                    $order->save_contact_button_color = '#ca8a04'; // Orange par défaut
                    $changes[] = "save_contact_button_color initialisé (couleur par défaut)";
                    $updatedOrder = true;
                }
                
                if (empty($order->services_button_color)) {
                    $order->services_button_color = '#0ea5e9'; // Bleu par défaut
                    $changes[] = "services_button_color initialisé (couleur par défaut)";
                    $updatedOrder = true;
                }
            }
            
            // 4. S'assurer que les champs JSON (employee_slots) sont correctement formatés
            if ($order->employee_slots && !is_array($order->employee_slots)) {
                $employeeSlots = is_string($order->employee_slots) ? json_decode($order->employee_slots, true) : null;
                if ($employeeSlots !== null) {
                    $order->employee_slots = $employeeSlots;
                    $changes[] = "employee_slots corrigé (JSON)";
                    $updatedOrder = true;
                }
            }
            
            // 5. Forcer le refresh pour s'assurer que les données sont à jour
            $order->refresh();
            
            if ($updatedOrder) {
                if (!$dryRun) {
                    try {
                        $order->save();
                        $updated++;
                        
                        $this->info("✓ Commande #{$order->order_number} (ID: {$order->id}) - Utilisateur: {$order->user->name} ({$order->user->email})");
                        foreach ($changes as $change) {
                            $this->line("  → {$change}");
                        }
                        
                        Log::info("UpdateIndividualOrders: Commande mise à jour", [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'user_id' => $order->user_id,
                            'changes' => $changes,
                        ]);
                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("✗ Erreur lors de la mise à jour de la commande #{$order->order_number}: {$e->getMessage()}");
                        Log::error("UpdateIndividualOrders: Erreur lors de la mise à jour", [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    $this->info("[DRY-RUN] Commande #{$order->order_number} (ID: {$order->id}) - Utilisateur: {$order->user->name} ({$order->user->email})");
                    foreach ($changes as $change) {
                        $this->line("  → {$change}");
                    }
                    $updated++;
                }
            } else {
                $skipped++;
            }
        }
        
        $this->newLine();
        $this->info("Résumé:");
        $this->line("  - Commandes mises à jour: {$updated}");
        $this->line("  - Commandes ignorées (déjà à jour): {$skipped}");
        if ($errors > 0) {
            $this->error("  - Erreurs: {$errors}");
        }
        
        if ($dryRun) {
            $this->newLine();
            $this->warn("Mode DRY-RUN: Aucune modification n'a été appliquée.");
            $this->info("Exécutez la commande sans --dry-run pour appliquer les modifications.");
        }
        
        return Command::SUCCESS;
    }
}
