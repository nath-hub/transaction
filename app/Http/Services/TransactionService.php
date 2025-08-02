<?php

namespace App\Http\Services;

use App\Helpers\InternalHttpClient;
use App\Models\Transaction;
use App\Models\CompanyWallet;
use App\Models\WalletMovement;
use App\Models\Operator;
use App\Models\Country;
use App\Models\CommissionSetting;
use App\Models\TransactionCommissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InternalIterator;

class TransactionService
{
    /**
     * Créer une nouvelle transaction
     */
    public function createTransaction(Request $request, array $data)
    {
        return DB::transaction(function () use ($request, $data) {
            // 1. Récupérer l'adresse IP et déterminer le pays
            $ipAddress = '143.105.152.76';//request()->ip();
            $countryInfo = $this->getCountryFromIp($ipAddress);

            // 2. Vérifier si le pays est autorisé
            $authServiceUrl = config('services.services_user.url');

            $httpClient = new InternalHttpClient();

             $country = $httpClient->get($request, $authServiceUrl, 'api/countries/code/'. $countryInfo['country_code'], ['read:users']);

            if (!$country) {
                throw new Exception("Pays non autorisé pour les transactions");
            }

            // 3. Récupérer l'opérateur
            $operator = $httpClient->get($request, $authServiceUrl, 'api/operators/code/' . $data['operator_code'], ['read:operator']);

            if (!$operator) {
                if (!$operator) {
                    throw new Exception("Opérateur non trouvé ou inactif");
                }
            }

            // return [$country, $operator];

            // $country = $operator['data']['country'];

            // if ($country->name !== $countryInfo['country_name']) {
            //     throw new Exception("Pays non autorisé pour les transactions");
            // }




            // 4. Récupérer la clé API depuis les headers
            $apiKey = request()->header('X-API-Public-Key');
            if (!$apiKey) {
                throw new Exception("Clé API manquante");
            }

            // 5. Calculer les commissions
            $commissions = $this->calculateCommissions(
                $data['amount'],
                $operator['data']['id'],
                $data['transaction_type']
            );

            // 6. Créer ou mettre à jour le portefeuille de l'entreprise
            $wallet = $this->getOrCreateCompanyWallet(
                $data['entreprise_id'],
                $country['data']['id'],
                $country['data']['code']
            );
 

            // 7. Vérifier les limites du portefeuille
            $this->checkWalletLimits($wallet, $data['amount'], $data['transaction_type']);

            // 8. Calculer le nouveau solde
            $balanceBefore = $wallet->balance;
            $netAmount = $data['amount'] - $commissions['operator_commission'] - $commissions['internal_commission'];

            if ($data['transaction_type'] === 'deposit') {
                $balanceAfter = $balanceBefore + $netAmount;
                $movementType = 'credit';
            } else { // withdrawal
                if ($balanceBefore < $data['amount']) {
                    throw new Exception("Solde insuffisant");
                }
                $balanceAfter = $balanceBefore - $data['amount'];
                $movementType = 'debit';
            }

            // 9. Créer la transaction
            $transaction = Transaction::create([
                'id' => Str::uuid(),
                'entreprise_id' => $data['entreprise_id'],
                'wallet_id' => $wallet->id,
                'operator_id' => $operator['data']['id'],
                'user_id' => $data['user_id'] ?? null,
                'transaction_type' => $data['transaction_type'],
                'amount' => $data['amount'],
                'currency_code' => $country['data']['currency_code'],
                'operator_commission' => $commissions['operator_commission'],
                'internal_commission' => $commissions['internal_commission'],
                'net_amount' => $netAmount,
                'status' => 'FAILED', // Par défaut, sera mis à jour après traitement PENDING
                'customer_phone' => $data['customer_phone'],
                'customer_name' => $data['customer_name'] ?? null,
                'initiated_at' => now(),
                'api_key_used' => $apiKey,
                'ip_address' => $ipAddress,
                'user_agent' => request()->userAgent(),
                'metadata' => json_encode($data['metadata'] ?? [])
            ]);

            // 10. Créer le mouvement de portefeuille
            $walletMovement = WalletMovement::create([
                'id' => Str::uuid(),
                'wallet_id' => $wallet->id,
                'transaction_id' => $transaction->id,
                'movement_type' => $movementType,
                'amount' => $data['amount'],
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $this->generateMovementDescription($data['transaction_type'], $data['customer_phone']),
                'reference' => $this->generateReference(),
                'created_by' => $data['user_id'] ?? null
            ]);

             Log::info($commissions['commission']);
              Log::info($commissions['commission']->id);
              Log::info($commissions['commission']['id']);

              // Snapshot de la configuration utilisée
            $configSnapshot = [
                'operator_rate' => $commissions['commission']->commission_value, 
                'operator_value' => $commissions['operator_commission'], 
                'internal_value' => $commissions['internal_commission'],
                'internal_rate' => 1,
                'config_id' => $commissions['commission']->id,
                'config_updated_at' => $commissions['commission']->updated_at
            ];

           

             // Préparer les détails du calcul
            $calculationDetails = [
                'base_amount' => $data['amount'],
                'operator_calculation' => $commissions['operator_commission'],
                'internal_calculation' => $commissions['internal_commission'],
                'total_commission' => $commissions['operator_commission'] + $commissions['internal_commission'],
                'net_amount' => $netAmount,
                'calculated_at' => now()->toISOString()
            ];

             // Créer l'enregistrement de commission
            $transactionCommission = TransactionCommissions::create([
                'id' => Str::uuid(),
                'transaction_id' => $transaction->id,
                'operator_id' => $operator['data']['id'],
                'entreprise_id' => $data['entreprise_id'],
                'transaction_amount' => $data['amount'],
                'currency_code' => $country['data']['currency_code'],
                'operator_commission_rate' => $commissions['commission']['commission_value'],
                'operator_commission_amount' => $commissions['operator_commission'],
                'internal_commission_rate' => 1,
                'internal_commission_amount' => $commissions['internal_commission'],
                'total_commission' => $commissions['operator_commission'] + $commissions['internal_commission'],
                'net_amount' => $netAmount,
                'transaction_type' => $data['transaction_type'],
                'commission_config_snapshot' => $configSnapshot,
                'calculation_details' => json_encode($calculationDetails),
                'calculated_by' => $data['user_id'] ?? null,
                'calculated_at' => now()
            ]);

            // 11. Mettre à jour le solde du portefeuille
            $wallet->update(['balance' => $balanceAfter]);

            // 12. Traiter la transaction avec l'opérateur
            // $this->processWithOperator($transaction, $operator);

            return [
                'transaction' => $transaction,
                'wallet_movement' => $walletMovement,
                'wallet' => $wallet->fresh()
            ];
        });
    }

    /**
     * Obtenir le pays à partir de l'adresse IP
     */
    private function getCountryFromIp($ipAddress)
    {
        try {
            // Utiliser un service de géolocalisation IP (exemple avec ipapi.co)
            $response = Http::timeout(5)->get("http://ipapi.co/{$ipAddress}/json/");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'country_code' => $data['country_code'] ?? 'CM', // Défaut Cameroun
                    'country_name' => $data['country_name'] ?? 'Cameroon'
                ];
            }
        } catch (Exception $e) {
            // Log l'erreur mais continue avec le pays par défaut
            logger()->error("Erreur géolocalisation IP: " . $e->getMessage());
        }

        // Pays par défaut si la géolocalisation échoue
        return [
            'country_code' => 'CM',
            'country_name' => 'Cameroon'
        ];
    }

    /**
     * Calculer les commissions
     */
    private function calculateCommissions($amount, $operatorId, $transactionType)
    {
        $commissionSetting = CommissionSetting::where('operator_id', $operatorId)
            ->where('transaction_type', $transactionType)
            ->where('is_active', true)
            ->first();
 

        if (!$commissionSetting) {
            return [
                'operator_commission' => 0,
                'internal_commission' => 1
            ];
        }

        $operatorCommission = ($amount * $commissionSetting->commission_value) / 100;
        $internalCommission = ($amount * 1) / 100;

        return [
            'operator_commission' => round($operatorCommission, 2),
            'internal_commission' => round($internalCommission, 2),
            'commission' => $commissionSetting
        ];
    }

    /**
     * Obtenir ou créer le portefeuille de l'entreprise
     */
    private function getOrCreateCompanyWallet($entrepriseId, $countryId, $currencyCode)
    {
        return CompanyWallet::firstOrCreate(
            [
                'entreprise_id' => $entrepriseId,
                'country_id' => $countryId,
                'currency_code' => $currencyCode
            ],
            [
                'id' => Str::uuid(),
                'balance' => 0.00,
                'status' => 'active'
            ]
        );
    }

    /**
     * Vérifier les limites du portefeuille
     */
    private function checkWalletLimits($wallet, $amount, $transactionType)
    {
        if ($wallet->status !== 'active') {
            throw new Exception("Portefeuille non actif");
        }

        // Vérifier limite journalière
        if ($wallet->daily_limit) {
            $todayTransactions = Transaction::where('wallet_id', $wallet->id)
                ->where('transaction_type', $transactionType)
                ->whereDate('created_at', today())
                ->where('status', 'SUCCESSFULL')
                ->sum('amount');

            if (($todayTransactions + $amount) > $wallet->daily_limit) {
                throw new Exception("Limite journalière dépassée");
            }
        }

        // Vérifier limite mensuelle
        if ($wallet->monthly_limit) {
            $monthTransactions = Transaction::where('wallet_id', $wallet->id)
                ->where('transaction_type', $transactionType)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->where('status', 'SUCCESSFULL')
                ->sum('amount');

            if (($monthTransactions + $amount) > $wallet->monthly_limit) {
                throw new Exception("Limite mensuelle dépassée");
            }
        }
    }

    /**
     * Générer une description du mouvement
     */
    private function generateMovementDescription($transactionType, $customerPhone)
    {
        $type = $transactionType === 'deposit' ? 'Dépôt' : 'Retrait';
        return "{$type} - {$customerPhone}";
    }

    /**
     * Générer une référence unique
     */
    private function generateReference()
    {
        return 'REF-' . strtoupper(Str::random(10));
    }

    /**
     * Traiter la transaction avec l'opérateur
     */
    private function processWithOperator($transaction, $operator)
    {
        try {
            // Simuler l'appel à l'API de l'opérateur
            // Cette partie dépendra de l'API spécifique de chaque opérateur

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $operator->api_key,
                    'Content-Type' => 'application/json'
                ])
                ->post($operator->api_endpoint, [
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency_code,
                    'customer_phone' => $transaction->customer_phone,
                    'transaction_type' => $transaction->transaction_type,
                    'reference' => $transaction->id
                ]);

            if ($response->successful()) {
                $data = $response->json();

                $transaction->update([
                    'status' => $data['status'] ?? 'SUCCESSFULL',
                    'operator_status' => $data['operator_status'] ?? null,
                    'operator_transaction_id' => $data['transaction_id'] ?? null,
                    'completed_at' => now()
                ]);
            } else {
                $transaction->update([
                    'status' => 'FAILED',
                    'failure_reason' => 'Erreur API opérateur: ' . $response->body()
                ]);
            }
        } catch (Exception $e) {
            $transaction->update([
                'status' => 'FAILED',
                'failure_reason' => 'Exception: ' . $e->getMessage()
            ]);
        }
    }
}