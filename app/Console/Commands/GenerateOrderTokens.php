<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class GenerateOrderTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:generate-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Génère les tokens d\'accès pour toutes les commandes validées qui n\'en ont pas encore';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Génération des tokens d\'accès pour les commandes validées...');

        // Récupérer toutes les commandes validées sans token
        $orders = Order::where('status', 'validated')
            ->whereNull('access_token')
            ->get();

        $this->info("Nombre de commandes à traiter: {$orders->count()}");

        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        $generated = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                $order->access_token = $order->generateAccessToken();
                $order->saveQuietly(); // Sauvegarder sans déclencher les événements
                $generated++;
            } catch (\Exception $e) {
                $this->error("\nErreur pour la commande #{$order->id}: {$e->getMessage()}");
                $errors++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("✅ Tokens générés avec succès: {$generated}");
        if ($errors > 0) {
            $this->warn("⚠️  Erreurs: {$errors}");
        }

        return Command::SUCCESS;
    }
}

