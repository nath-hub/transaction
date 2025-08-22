<?php

namespace App\Console\Commands;

use App\Jobs\CheckAllPendingTransactionsJob;
use Illuminate\Console\Command;

class CheckPendingTransactions extends Command
{
  protected $signature = 'transactions:check-pending';
    protected $description = 'Vérifie toutes les transactions pending auprès de l\'opérateur';

    public function handle()
    {
        $this->info('Lancement de la vérification des transactions pending...');
        
        CheckAllPendingTransactionsJob::dispatch();
        
        $this->info('Job de vérification dispatché avec succès !');
    }
}
