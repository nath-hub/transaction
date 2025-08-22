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

class CheckTransactionStatusJobs implements ShouldQueue
{ 

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transactionId;
    protected $attempt;

    public $tries = 1; // Pas de retry automatique Laravel
    public $timeout = 60;

    public function __construct(string $transactionId, int $attempt = 1)
    {
        $this->transactionId = $transactionId;
        $this->attempt = $attempt;

        // Délai de 10 secondes avant exécution
        $this->delay(now()->addSeconds(10));
    }

    public function handle()
    {
        Log::info("Vérification transaction", [
            'id' => $this->transactionId,
            'attempt' => $this->attempt
        ]);

        // 1. Récupérer la transaction
        $transaction = Transaction::where('id', $this->transactionId)->first();

        if (!$transaction) {
            Log::error("Transaction non trouvée", ['id' => $this->transactionId]);
            return;
        }

        $authServiceUrl = config('services.services_user.url');

        $operator = Http::get($authServiceUrl . '/api/operators/' . $transaction->operator_id);

        // 2. Si plus pending, arrêter
        if ($transaction->status !== 'PENDING') {
            Log::info("Transaction plus pending, arrêt", [
                'id' => $this->transactionId,
                'status' => $transaction->status
            ]);
            return;
        }

        try {
            // 3. Vérifier le statut auprès de l'opérateur
            $operatorService = app(OperatorService::class);
            $newStatus = $operatorService->checkPaymentStatus(
                $operator['code'],
                $transaction
            );

            Log::info("Statut vérifié", [
                'id' => $this->transactionId,
                'new_status' => $newStatus,
                'attempt' => $this->attempt
            ]);

            if ($newStatus === 'PENDING') {
                // 4a. Status pas changé - remettre en queue
                if ($this->attempt < 30) { // Max 30 tentatives = 5 minutes
                    

                    // Relancer le job dans 10 secondes
                    static::dispatch($this->transactionId, $this->attempt + 1);
                } else {
                    // Trop de tentatives, marquer comme failed
                    // $transaction->update(['status' => 'FAILED']);
                    $this->sendWebhook($transaction, 'FAILED');

                   
                }
            } else {
                // 4b. Status changé - mettre à jour et webhook
                // $transaction->update(['status' => $newStatus['data']['status']]);
                $this->sendWebhook($transaction, $newStatus);

                Log::info("Transaction finalisée", [
                    'id' => $this->transactionId,
                    'final_status' => $newStatus
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Erreur vérification statut", [
                'id' => $this->transactionId,
                'attempt' => $this->attempt,
                'error' => $e->getMessage()
            ]);

            // Relancer en cas d'erreur
            if ($this->attempt < 30) {
                static::dispatch($this->transactionId, $this->attempt + 1);
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
