<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class FixSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:fix-super-admin {--email=charlesgabrielgoumou@gmail.com} {--password=Charles2022}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie et corrige le compte super admin (crée ou met à jour)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->option('email');
        $password = $this->option('password');
        
        $this->info("🔍 Vérification du compte super admin...");
        $this->info("Email: {$email}");
        
        // Vérifier tous les comptes avec cet email
        $allUsersWithEmail = User::where('email', $email)->get();
        
        if ($allUsersWithEmail->isEmpty()) {
            $this->warn("Aucun compte trouvé avec cet email.");
        } else {
            $this->info("📋 Comptes trouvés avec cet email:");
            foreach ($allUsersWithEmail as $user) {
                $this->line("  - ID: {$user->id}, Rôle: {$user->role}, Nom: {$user->name}");
            }
        }
        
        // Vérifier spécifiquement le compte super_admin
        $superAdmin = User::where('email', $email)
            ->where('role', 'super_admin')
            ->first();
        
        if ($superAdmin) {
            $this->info("✅ Compte super_admin trouvé (ID: {$superAdmin->id})");
            
            // Mettre à jour le mot de passe et les autres champs
            $superAdmin->update([
                'password' => Hash::make($password),
                'is_admin' => true,
                'email_verified_at' => now(),
                'initial_password_set' => true,
            ]);
            
            $this->info("✅ Mot de passe mis à jour avec succès!");
            $this->info("📧 Email: {$email}");
            $this->info("🔑 Mot de passe: {$password}");
            $this->info("👤 Rôle: super_admin");
            
        } else {
            $this->warn("❌ Aucun compte super_admin trouvé. Création...");
            
            // Créer le compte super_admin
            $superAdmin = User::create([
                'email' => $email,
                'name' => 'Charles Gabriel Goumou',
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'is_admin' => true,
                'email_verified_at' => now(),
                'initial_password_set' => true,
            ]);
            
            $this->info("✅ Compte super_admin créé avec succès!");
            $this->info("📧 Email: {$email}");
            $this->info("🔑 Mot de passe: {$password}");
            $this->info("👤 Rôle: super_admin");
        }
        
        // Vérifier que l'authentification fonctionne
        $this->info("\n🔐 Test d'authentification...");
        // Utiliser Hash::check directement sur le compte super_admin trouvé
        if (Hash::check($password, $superAdmin->password)) {
            $this->info("✅ Mot de passe vérifié avec succès!");
            $this->info("   - ID: {$superAdmin->id}");
            $this->info("   - Nom: {$superAdmin->name}");
            $this->info("   - Email: {$superAdmin->email}");
            $this->info("   - Rôle: {$superAdmin->role}");
            $this->info("   - is_admin: " . ($superAdmin->is_admin ? 'Oui' : 'Non'));
            $this->info("\n💡 Note: La connexion via l'API devrait maintenant fonctionner car le contrôleur");
            $this->info("   cherche spécifiquement un compte admin/super_admin avec cet email.");
        } else {
            $this->error("❌ Échec de la vérification du mot de passe!");
        }
        
        return 0;
    }
}
