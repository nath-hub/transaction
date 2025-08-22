<?php
// app/Services/PaymentProviderService.php

namespace App\Http\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OperatorService
{

    public function initiatePayment($operator, $amount, $phoneNumber)
    {
        switch ($operator) {
            case 'OM':
                return $this->initiateOrangeMoneyPayment($amount, $phoneNumber);
            case 'MOMO':
                return $this->initiateMomoPayment($amount, $phoneNumber);
            default:
                return response()->json([
                    'success' => false,
                    'message' => "Opérateur non supporté {$operator}",
                    'status' => 500
                ], 500);
        }
    }

    public function checkPaymentStatus($operator, $transaction)
    {
        switch ($operator) {
            case 'OM':
                return $this->checkOrangeMoneyStatus($transaction);
            case 'MOMO':
                return $this->checkMomoStatus($transaction);
            default:
                throw new \Exception("Opérateur non supporté: {$operator}");
        }
    }

    private function initiateOrangeMoneyPayment($amount, $phoneNumber)
    {
        $token = self::getToken();

        $result = self::initialisation($token['access_token']);

        $response = self::paiement($result['data']['payToken'], $token['access_token'], $phoneNumber, $amount);

        return ['payToken' => $result['data']['payToken'], 'token' => $token['access_token'], 'response' => $response];
    }

    private function initiateMomoPayment($amount, $phoneNumber)
    {
        // Simulation - remplacez par la vraie API MTN MoMo
        $response = Http::post('https://api.mtn.com/momo/payment/initiate', [
            'amount' => $amount,
            'phone' => $phoneNumber,
            // autres paramètres requis
        ]);

        if ($response->successful()) {
            return [
                'transaction_id' => $response->json('transaction_id'),
                'status' => 'pending'
            ];
        }

        throw new \Exception('Erreur lors de l\'initiation du paiement MoMo');
    }

    private function checkOrangeMoneyStatus($transaction)
    {
        $accessToken = $transaction->access_token;
        $payToken = $transaction->payToken;

        $authToken = ENV('X_AUTH_TOKEN');

        $url = "https://api-s1.orange.cm/omcoreapis/1.0.2/mp/paymentstatus/" . $payToken;

        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'X-AUTH-TOKEN' => $authToken,
        ]; 

        $response = Http::withHeaders($headers)->get($url);

        if ($response->successful()) {
            $data = $response->json();
            $status = $data['data']['status']; // Supposons que 'status' soit dans la réponse

            if (in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED', 'SUCCESSFULL'])) {
                $transaction->status = $status;
                $transaction->completed_at = now();

                if (in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED',])) {
                    $transaction->failure_reason = $data['data']['confirmtxnmessage'];
                }

                $transaction->save();
            }
            return $data['data']['status'];

        } else {

            $data = "FAILED";
            $transaction->status = $data;
            $transaction->completed_at = now();
            $transaction->failure_reason = $response;
            $transaction->save();
 
            return $data;
        }

    }

    private function checkMomoStatus($transactionId)
    {
        // Simulation - remplacez par la vraie API MTN MoMo
        $response = Http::get("https://api.mtn.com/momo/payment/status/{$transactionId}");

        if ($response->successful()) {
            return $response->json('status'); // 'pending', 'success', 'failed'
        }

        return 'pending'; // En cas d'erreur, on assume pending
    }


    public function getToken()
    {
        $baseUrl = 'https://api-s1.orange.cm';
        // Vérifier si on a déjà un token en cache
        $cachedToken = Cache::get('orange_money_token');
        if ($cachedToken) {
            return $cachedToken;
        }

        $username = env('CUSTOMER_KEY');
        $password = env('CUSTOMER_SECRET');

        if (!$username || !$password) {
            throw new \Exception('Credentials Orange Money manquants dans .env');
        }

        $credentials = base64_encode($username . ':' . $password);


        $response = Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . $credentials,
            ])
            ->withBasicAuth($username, $password)
            ->asForm()
            ->post($baseUrl . '/token', [
                'grant_type' => 'client_credentials'
            ]);

        if ($response->successful()) {
            $tokenData = $response->json();

            // Mettre en cache le token (expire généralement en 1h)
            $expiresIn = $tokenData['expires_in'] ?? 3600;
            Cache::put('orange_money_token', $tokenData, now()->addSeconds($expiresIn - 60));

            return $tokenData;
        }


        return null;

    }

    function initialisation($token)
    {
        $auth_token = ENV('X_AUTH_TOKEN');

        $response = Http::withHeaders([
            'X-AUTH-TOKEN' => $auth_token,
            'Authorization' => 'Bearer ' . $token,
        ])->post('https://api-s1.orange.cm/omcoreapis/1.0.2/mp/init');

        // Récupérer la réponse
        $data = $response->json();
        return $data;
    }


    function paiement($payToken, $token, $number, $montant)
    {

        $authToken = ENV('X_AUTH_TOKEN');
        $accessToken = "Bearer " . $token;

        $amount = $montant;
        $channelUserMsisdn = ENV('channelUserMsisdn');
        $pin = ENV('pin');
        $url_notification = asset('/api/notification');

        $data = [
            "notifUrl" => $url_notification,
            "channelUserMsisdn" => $channelUserMsisdn,
            "amount" => (string) $amount,
            "subscriberMsisdn" => (string) $number,
            "pin" => (string) $pin,
            "orderId" => "order1234",
            "description" => "Paiement",
            "payToken" => (string) $payToken,
        ];

        $url = "https://api-s1.orange.cm/omcoreapis/1.0.2/mp/pay";

        $headers = [
            'Authorization' => $accessToken,
            'X-AUTH-TOKEN' => $authToken,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)
            ->withBody(json_encode($data), 'application/json')
            ->post($url);

        return $response->json();
    }


}