<?php

namespace App\Jobs;

use App\Http\Services\OperatorService;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckAllPendingTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $pendingTransactions = Transaction::where('status', 'pending')
            ->where('created_at', '>=', now()->subHours(24)) // Limite aux 24 dernières heures
            ->get();

        Log::info("Vérification périodique : {$pendingTransactions->count()} transactions pending trouvées");

        foreach ($pendingTransactions as $transaction) {

            $authServiceUrl = config('services.services_user.url');

            $operator = Http::get($authServiceUrl . '/api/operators/' . $transaction->operator_id);

            try {
                // 3. Vérifier le statut auprès de l'opérateur
                $operatorService = app(OperatorService::class);
                $newStatus = $operatorService->checkPaymentStatus(
                    $operator['code'],
                    $transaction
                );

                if ($newStatus !== 'PENDING') {

                    // 4b. Status changé - mettre à jour et webhook
                    $transaction->update(['status' => $newStatus]);
                    $this->sendWebhook($transaction, $newStatus);

                    Log::info("Transaction finalisée", [
                        'id' => $transaction->id,
                        'final_status' => $newStatus
                    ]);
                }

            } catch (\Exception $e) {
                Log::error("Erreur vérification statut", [
                    'id' => $transaction->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }


    private function sendWebhook(Transaction $transaction, string $status)
    {
        if (!$transaction->webhook_url) {
            return;
        }

        try {
            // $webhookService = app(WebhookService::class);

            // $webhookData = [
            //     'id' => $transaction->id,
            //     'status' => $status,
            //     'amount' => $transaction->amount,
            //     'operator' => $transaction->operator,
            //     'phone_number' => $transaction->phone_number,
            //     'timestamp' => now()->toISOString(),
            //     'metadata' => $transaction->metadata
            // ];

            // $webhookService->sendWebhook($transaction->webhook_url, $webhookData);

        } catch (\Exception $e) {
            Log::error("Erreur webhook", [
                'id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}