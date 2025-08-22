<?php
// app/Services/WebhookService.php

namespace App\Http\Services;
 
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; 

class WebhookService
{
   public function sendWebhook($webhookUrl, $transactionData)
    {
        try {
            $response = Http::timeout(30)
                ->retry(3, 1000)
                ->post($webhookUrl, [
                    'transaction_id' => $transactionData['transaction_id'],
                    'status' => $transactionData['status'],
                    'amount' => $transactionData['amount'],
                    'operator' => $transactionData['operator'],
                    'phone_number' => $transactionData['phone_number'],
                    'timestamp' => now()->toISOString(),
                    'metadata' => $transactionData['metadata'] ?? null
                ]);

            if ($response->successful()) {
                Log::info("Webhook envoyé avec succès", [
                    'transaction_id' => $transactionData['transaction_id'],
                    'webhook_url' => $webhookUrl,
                    'status_code' => $response->status()
                ]);
                return true;
            }

            Log::error("Échec de l'envoi du webhook", [
                'transaction_id' => $transactionData['transaction_id'],
                'webhook_url' => $webhookUrl,
                'status_code' => $response->status(),
                'response' => $response->body()
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error("Erreur lors de l'envoi du webhook", [
                'transaction_id' => $transactionData['transaction_id'],
                'webhook_url' => $webhookUrl,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}