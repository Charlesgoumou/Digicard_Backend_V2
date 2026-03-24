<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {email} {--name=} {--password=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée un compte administrateur (super_admin)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $name = $this->option('name') ?: 'Administrateur';
        $password = $this->option('password') ?: Str::random(12);
        
        $this->info("🔍 Création du compte administrateur...");
        $this->info("📧 Email: {$email}");
        
        // Vérifier si un compte super_admin existe déjà avec cet email
        $existingAdmin = User::where('email', $email)
            ->where('role', 'super_admin')
            ->first();
        
        if ($existingAdmin) {
            $this->warn("⚠️  Un compte super_admin existe déjà avec cet email (ID: {$existingAdmin->id})");
            
            if ($this->confirm('Voulez-vous mettre à jour le mot de passe ?', false)) {
                $newPassword = $this->option('password') ?: $this->secret('Entrez le nouveau mot de passe (ou laissez vide pour générer un mot aléatoire)');
                
                if (empty($newPassword)) {
                    $newPassword = Str::random(12);
                }
                
                $existingAdmin->update([
                    'password' => Hash::make($newPassword),
                    'is_admin' => true,
                    'email_verified_at' => now(),
                    'initial_password_set' => true,
                ]);
                
                $this->info("✅ Mot de passe mis à jour avec succès!");
                $this->info("🔑 Mot de passe: {$newPassword}");
                return 0;
            } else {
                $this->info("❌ Opération annulée.");
                return 1;
            }
        }
        
        // Générer un username unique
        $baseUsername = Str::slug($name, '.');
        $username = $baseUsername;
        $counter = 1;
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '.' . $counter;
            $counter++;
        }
        
        // Créer le compte super_admin
        try {
            $admin = User::create([
                'email' => $email,
                'name' => $name,
                'username' => $username,
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'is_admin' => true,
                'email_verified_at' => now(),
                'initial_password_set' => true,
            ]);
            
            $this->info("✅ Compte super_admin créé avec succès!");
            $this->info("📧 Email: {$email}");
            $this->info("👤 Nom: {$name}");
            $this->info("🔑 Mot de passe: {$password}");
            $this->info("👤 Rôle: super_admin");
            $this->info("🆔 ID: {$admin->id}");
            $this->info("📝 Username: {$username}");
            
            // Vérifier que l'authentification fonctionne
            $this->info("\n🔐 Test d'authentification...");
            if (Hash::check($password, $admin->password)) {
                $this->info("✅ Mot de passe vérifié avec succès!");
            } else {
                $this->error("❌ Échec de la vérification du mot de passe!");
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Erreur lors de la création du compte: " . $e->getMessage());
            return 1;
        }
    }
}
