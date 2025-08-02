<?php

namespace App\Http\Services;

use App\Helpers\InternalHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiKeyVerificationService
{
    // private $httpClient;
    private InternalHttpClient $httpClient;
    private $apiKeysServiceUrl = "http://127.0.0.1:8002";
    private $cache = null;

    public function __construct()
    {
        $bearerToken = request()->bearerToken();
        $this->httpClient = new InternalHttpClient($bearerToken);
    }
    // {
    //     $this->httpClient = $httpClient;
    //     $this->apiKeysServiceUrl = rtrim($apiKeysServiceUrl, '/');
    //     $this->cache = $cache;
    // }

    public function verifyApiKeys($request, $data)
    {
        try {

            $orderServiceUrl = config('services.services_apikeys.url');

            $orderData = [
                'public_key' => $data['public_key'],
                'private_key' => $data['private_key'],
                'environment' => $data['environment'],
                'uuid' => $data['uuid'], 
                'timestamp' => time()
            ];

            $response = $this->httpClient->post(
                $request,
                $orderServiceUrl,
                '/api/apikeys/verify_keys',
                $orderData,
                ['create:orders']
            );

            if ($response['success']) {
                Log::info($response['data']);
                return $response['data'];
            }

            return response()->json([
                'error' => 'Failed to create order',
                'details' => $response['error']
            ], $response['status_code']);

         
          

        } catch (\Exception $e) {
            Log::error('API Key verification failed', [
                // 'public_key' => $publicKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'valid' => false,
                'error' => 'Verification service error',
                'code' => 'VERIFICATION_ERROR'
            ];
        }
    }

    /**
     * Vérifie si les données en cache sont encore valides
     */
    private function isValidCachedData(array $cachedData, string $action): bool
    {
        if (!isset($cachedData['valid']) || !$cachedData['valid']) {
            return false;
        }

        // Vérifier si l'action est autorisée selon les permissions cachées
        if ($action && isset($cachedData['permissions'])) {
            return $this->checkActionPermission($cachedData['permissions'], $action);
        }

        return true;
    }

    /**
     * Vérifie si une action est autorisée selon les permissions
     */
    private function checkActionPermission(array $permissions, string $action): bool
    {
        // Format action: "payments.create", "refunds.read", etc.
        $parts = explode('.', $action);
        if (count($parts) !== 2) {
            return false;
        }

        [$resource, $operation] = $parts;

        return isset($permissions[$resource][$operation]) && $permissions[$resource][$operation] === true;
    }
}
