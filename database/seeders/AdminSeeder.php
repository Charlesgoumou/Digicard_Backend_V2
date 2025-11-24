<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // IMPORTANT: Utiliser (email, role) comme clé car la contrainte unique est sur (email, role)
        // Cela permet à un même email d'avoir plusieurs rôles (individual, business_admin, super_admin)
        $email = 'charlesgabrielgoumou@gmail.com';
        $role = 'super_admin';
        
        // Vérifier si un compte super_admin existe déjà avec cet email
        $admin = User::where('email', $email)
            ->where('role', $role)
            ->first();
        
        if ($admin) {
            // Mettre à jour le mot de passe si nécessaire
            $admin->update([
                'password' => Hash::make('Charles2022'),
                'is_admin' => true,
                'email_verified_at' => now(),
                'initial_password_set' => true,
            ]);
            $this->command->info('Compte super admin mis à jour!');
        } else {
            // Créer le compte super admin
            User::create([
                'email' => $email,
                'name' => 'Charles Gabriel Goumou',
                'password' => Hash::make('Charles2022'),
                'role' => $role,
                'is_admin' => true,
                'email_verified_at' => now(),
                'initial_password_set' => true,
            ]);
            $this->command->info('Compte super admin créé avec succès!');
        }

        $this->command->info('Email: ' . $email);
        $this->command->info('Mot de passe: Charles2022');
        $this->command->info('Rôle: ' . $role);
    }
}

