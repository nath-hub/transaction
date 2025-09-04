<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class RemboursementService
{
    private const SUPPORTED_OPERATORS = ['OM', 'MOMO'];
    private const OM_TOKEN_URL = 'https://omapi-token.ynote.africa/oauth2/token';
    private const OM_REFUND_URL = 'https://omapi.ynote.africa/prod/refund';

    /**
     * Initiate refund based on operator
     */
    public function initiateRemboursement($operator, $user, $amount, $phoneNumber)
    {
        try {
            if (!in_array($operator, self::SUPPORTED_OPERATORS)) {
                return [
                    'success' => false,
                    'message' => "Opérateur non supporté: {$operator}",
                    'status' => 400
                ];
            }

            switch ($operator) {
                case 'OM':
                    return $this->refundOrDeposit($user, $amount, false);
                case 'MOMO':
                    return $this->initiateMomoPayment($amount, $phoneNumber);
                default:
                    return [
                        'success' => false,
                        'message' => "Opérateur non supporté: {$operator}",
                        'status' => 400
                    ];
            }
        } catch (Exception $e) {
            Log::error('Erreur lors de l\'initiation du remboursement', [
                'operator' => $operator,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erreur interne lors du remboursement',
                'status' => 500
            ];
        }
    }

    /**
     * Check refund status
     */
    public function checkRemboursementStatus($transaction)
    {
        try {
            if (!$transaction || !isset($transaction->operator)) {
                return [
                    'success' => false,
                    'message' => 'Transaction invalide',
                    'status' => 400
                ];
            }

            switch ($transaction->operator) {
                case 'OM':
                    return $this->checkOrangeMoneyStatus($transaction);
                case 'MOMO':
                    return $this->checkMomoStatus($transaction);
                default:
                    return [
                        'success' => false,
                        'message' => 'Opérateur non supporté pour la vérification',
                        'status' => 400
                    ];
            }
        } catch (Exception $e) {
            Log::error('Erreur lors de la vérification du statut', [
                'transaction_id' => $transaction->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erreur lors de la vérification du statut',
                'status' => 500
            ];
        }
    }

    /**
     * Get Orange Money API token
     */
    public function getTokenRemboursement()
    {
        $credentials = base64_encode(env('OMAPI_LOGIN') . ':' . env('OMAPI_PASSWORD'));

        $headers = [
            "Content-Type: application/x-www-form-urlencoded",
            "Authorization: Basic $credentials"
        ];

        $postData = http_build_query([
            'grant_type' => 'client_credentials'
        ]);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, self::OM_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Désactiver la vérification SSL (optionnel)

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $decoded = json_decode($response, true);
            return $decoded['access_token'] ?? null;
        } else {
            return ['error' => 'Échec de récupération du token', 'status' => $httpCode, 'response' => $response];
        }
    }

    /**
     * Process Orange Money refund or deposit
     */
    public function refundOrDeposit($user, $montant, $isDeposit = false)
    {

        if (!$user || !isset($user->phone, $user->name)) {
            return [
                'success' => false,
                'message' => 'Données utilisateur invalides',
                'status' => 400
            ];
        }

        if ($montant <= 0) {
            return [
                'success' => false,
                'message' => 'Montant invalide',
                'status' => 400
            ];
        }

        $tokenData = $this->getTokenRemboursement(); 

        $payload = [
            'customerkey' => env('CUSTOMER_KEY'),
            'customersecret' => env('CUSTOMER_SECRET'),
            'channelUserMsisdn' => env('channelUserMsisdn'),
            'pin' => env('pin'),
            'webhook' => 'http://example.com/api/notification',
            'amount' => (string)$montant,
            'final_customer_phone' => (string)$user->phone,
            'final_customer_name' => (string)$user->name,
            'refund_method' => 'OrangeMoney',
            'fees_included' => 'Yes',
            'final_customer_name_accuracy' => '10',
            'maximum_retries' => '9'
        ];

        if ($isDeposit) {
            $payload['debit_policy'] = 'deposit_acc_only';
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$tokenData}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ])->timeout(30)->post(self::OM_REFUND_URL, $payload);

        $result = $response->json();

        if (!$response->successful()) {
            
            if ($response->status() === 403) {
                return [
                    'success' => false,
                    'message' => 'Accès refusé par Orange Money. Vérifiez les permissions de votre compte.',
                    'status' => 403,
                    'details' => $result['message'] ?? 'No details'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Erreur lors du traitement',
                'status' => $response->status(),
                'details' => $result
            ];
        }

        return $result;
    }

    /**
     * Initiate Mobile Money payment
     */
    private function initiateMomoPayment($amount, $phoneNumber)
    {
        try {
            if ($amount <= 0) {
                return [
                    'success' => false,
                    'message' => 'Montant invalide',
                    'status' => 400
                ];
            }

            if (!$phoneNumber || !preg_match('/^[0-9+\-\s()]+$/', $phoneNumber)) {
                return [
                    'success' => false,
                    'message' => 'Numéro de téléphone invalide',
                    'status' => 400
                ];
            }

            // TODO: Implémenter l'API Mobile Money
            Log::info('Tentative de remboursement MOMO', [
                'amount' => $amount,
                'phone' => $phoneNumber
            ]);

            return [
                'success' => false,
                'message' => 'Service Mobile Money non encore implémenté',
                'status' => 501
            ];
        } catch (Exception $e) {
            Log::error('Erreur lors du remboursement MOMO', [
                'amount' => $amount,
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erreur interne lors du remboursement MOMO',
                'status' => 500
            ];
        }
    }

    /**
     * Check Orange Money transaction status
     */
    private function checkOrangeMoneyStatus($transaction)
    {
        // TODO: Implémenter la vérification du statut OM
        return [
            'success' => false,
            'message' => 'Vérification statut OM non encore implémentée',
            'status' => 501
        ];
    }

    /**
     * Check Mobile Money transaction status
     */
    private function checkMomoStatus($transaction)
    {
        // TODO: Implémenter la vérification du statut MOMO
        return [
            'success' => false,
            'message' => 'Vérification statut MOMO non encore implémentée',
            'status' => 501
        ];
    }
}
