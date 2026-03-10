<?php

namespace App\Console\Commands;

use App\Http\Controllers\SharedContactController;
use Illuminate\Console\Command;

class CleanupExpiredContacts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contacts:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Supprime les contacts partagés téléchargés depuis plus de 24 heures';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Nettoyage des contacts expirés...');
        
        $count = SharedContactController::cleanupExpiredVCards();
        
        if ($count > 0) {
            $this->info("✓ {$count} contact(s) expiré(s) supprimé(s).");
        } else {
            $this->info('Aucun contact expiré à supprimer.');
        }
        
        return Command::SUCCESS;
    }
}

