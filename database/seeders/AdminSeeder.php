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
        // Créer un compte super admin par défaut si il n'existe pas
        User::firstOrCreate(
            ['email' => 'charlesgabrielgoumou@gmail.com'],
            [
                'name' => 'Charles Gabriel Goumou',
                'password' => Hash::make('Charles2022'),
                'role' => 'super_admin',
                'is_admin' => true,
                'email_verified_at' => now(),
                'initial_password_set' => true,
            ]
        );

        $this->command->info('Compte admin créé avec succès!');
        $this->command->info('Email: charlesgabrielgoumou@gmail.com');
        $this->command->info('Mot de passe: Charles2022');
    }
}

