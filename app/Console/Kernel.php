<?php

namespace App\Console;

use App\Jobs\CheckAllPendingTransactionsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new CheckAllPendingTransactionsJob())
                 ->everyFiveMinutes()
                 ->withoutOverlapping(); // Évite les chevauchements
                //  ->runInBackground();   // Exécution en arrière-plan

        // Optionnel : Nettoyer les vieux jobs échoués chaque jour
        $schedule->command('queue:prune-failed --hours=48')
                 ->daily();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        Commands\CheckPendingTransactions::class;

        require base_path('routes/console.php');
    }
}
