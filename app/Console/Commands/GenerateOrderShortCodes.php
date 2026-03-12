<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class GenerateOrderShortCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:generate-short-codes {--force : Régénérer même si short_code existe} {--chunk=500 : Taille du chunk pour le traitement}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Génère rétroactivement des short_code uniques (6 chars base62) pour les commandes.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $force = (bool) $this->option('force');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $query = Order::query();
        if (!$force) {
            $query->whereNull('short_code');
        }

        $total = (int) $query->count();
        if ($total === 0) {
            $this->info('Aucune commande à traiter.');
            return self::SUCCESS;
        }

        $this->info("Traitement de {$total} commande(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $updated = 0;
        $query->orderBy('id')
            ->chunkById($chunkSize, function ($orders) use ($force, $bar, &$processed, &$updated) {
                /** @var \App\Models\Order $order */
                foreach ($orders as $order) {
                    $processed++;
                    if (!$force && $order->short_code) {
                        $bar->advance();
                        continue;
                    }

                    // Générer un code unique (anti-collision)
                    $order->short_code = $order->generateShortCode();
                    $order->saveQuietly();
                    $updated++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Terminé. Mis à jour: {$updated} / {$processed}.");

        return self::SUCCESS;
    }
}
