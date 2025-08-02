<?php

namespace App\Http\Middleware;

use App\Http\Services\ApiKeyVerificationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuthMiddleware
{

    private $verificationService;

    public function __construct(ApiKeyVerificationService $verificationService)
    {
        $this->verificationService = $verificationService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $action = '')
    {
        try {

            $authData = $this->extractAuthData($request);

            if (empty($authData)) {
                return $this->errorResponse(
                    'AUTH_MISSING',
                    'Authentication data is missing. Please provide API key or Bearer token.',
                    401,
                    [
                        'expected_headers' => ['X-API-Public-Key', 'X-API-Private-Key', 'X-API-UUID', 'X-API-Environment'],
                        'provided_headers' => array_keys($request->headers->all())
                    ]
                );
            }
 
            $verification = $this->verificationService->verifyApiKeys($request, $authData);

            Log::info($verification);
 
            // 3. Analyser le résultat de la vérification
            if (!isset($verification['valid'])) {
                return $this->errorResponse(
                    'VERIFICATION_ERROR',
                    'Authentication verification failed due to service error.',
                    400,
                    ['verification_response' => $verification]
                );
            }

            if (!$verification['valid']) {
                return $this->handleAuthenticationFailure($verification);
            }

            // 4. Ajouter les informations d'authentification à la requête
            $request->merge([
                'auth_user_id' => $verification['user_id'] ?? null,
                'auth_permissions' => $verification['permissions'] ?? [],
                'auth_environment' => $verification['environment'] ?? 'unknown',
                'auth_key_id' => $verification['key_id'] ?? null,
                'auth_verified_at' => now()->toISOString()
            ]);

            // 5. Log de succès
            Log::info('API Authentication successful', [
                'user_id' => $verification['user_id'] ?? 'unknown',
                'key_id' => $verification['key_id'] ?? 'unknown',
                'endpoint' => $request->getRequestUri()
            ]);


            return $next($request);


        } catch (\Exception $e) {
            Log::error('API Key middleware error', [
                'error' => $e->getMessage(),
                'request_id' => $request->header('X-Request-ID')
            ]);

            return response()->json([
                'error' => 'Authentication service error',
                'code' => 'AUTH_SERVICE_ERROR'
            ], 500);
        }
    }

    /**
     * Extrait les données d'authentification de la requête
     */
    private function extractAuthData($request): ?array
    {

        if ($request->hasHeader('X-API-Public-Key') && $request->hasHeader('X-API-Private-Key') && $request->hasHeader('X-API-UUID') && $request->hasHeader('X-API-Environment')) {

            return [
                'public_key' => $request->header('X-API-Public-Key'),
                'private_key' => $request->header('X-API-Private-Key'),
                'uuid' => $request->header('X-API-UUID'),
                'environment' => $request->header('X-API-Environment', 'test'),
                'request_data' => $request->getContent()
            ];
        }

        return null;
    }

    /**
     * Gérer les échecs d'authentification avec des messages spécifiques
     */
    private function handleAuthenticationFailure(array $verification)
    {
        $errorCode = $verification['error_code'] ?? 'AUTH_FAILED';
        $statusCode = $this->getStatusCodeFromErrorCode($errorCode);

        $errorMessages = [
            'INVALID_API_KEY' => 'The provided API key is invalid or has been revoked.',
            'EXPIRED_API_KEY' => 'The API key has expired. Please generate a new one.',
            'SUSPENDED_API_KEY' => 'The API key has been suspended. Contact support for assistance.',
            'INVALID_TOKEN' => 'The Bearer token is invalid or malformed.',
            'EXPIRED_TOKEN' => 'The Bearer token has expired. Please refresh your token.',
            'INSUFFICIENT_PERMISSIONS' => 'Your API key does not have sufficient permissions for this operation.',
            'RATE_LIMIT_EXCEEDED' => 'Rate limit exceeded. Please wait before making more requests.',
            'IP_NOT_ALLOWED' => 'Your IP address is not authorized to use this API key.',
            'ENVIRONMENT_MISMATCH' => 'API key environment does not match the current environment.',
            'KEY_NOT_FOUND' => 'The provided API key was not found in our system.',
        ];

        $message = $errorMessages[$errorCode] ?? 'Authentication failed for an unknown reason.';

        return $this->errorResponse(
            $errorCode,
            $message,
            $statusCode,
            $this->getErrorDetails($verification)
        );
    }



    /**
     * Obtenir le code de statut HTTP approprié selon le type d'erreur
     */
    private function getStatusCodeFromErrorCode(string $errorCode): int
    {
        return match ($errorCode) {
            'INVALID_API_KEY', 'INVALID_TOKEN', 'KEY_NOT_FOUND' => 401,
            'EXPIRED_API_KEY', 'EXPIRED_TOKEN' => 401,
            'SUSPENDED_API_KEY', 'INSUFFICIENT_PERMISSIONS', 'IP_NOT_ALLOWED' => 403,
            'RATE_LIMIT_EXCEEDED' => 429,
            'ENVIRONMENT_MISMATCH' => 400,
            default => 401
        };
    }

    /**
     * Obtenir les détails d'erreur à inclure dans la réponse
     */
    private function getErrorDetails(array $verification): array
    {
        $details = [];

        // Ajouter des détails selon le type d'erreur
        if (isset($verification['expires_at'])) {
            $details['expires_at'] = $verification['expires_at'];
        }

        if (isset($verification['permissions'])) {
            $details['available_permissions'] = $verification['permissions'];
        }

        if (isset($verification['rate_limit'])) {
            $details['rate_limit'] = $verification['rate_limit'];
        }

        if (isset($verification['allowed_ips'])) {
            $details['allowed_ips'] = $verification['allowed_ips'];
        }

        if (isset($verification['environment'])) {
            $details['expected_environment'] = $verification['environment'];
        }

        return $details;
    }

    /**
     * Créer une réponse d'erreur standardisée
     */
    private function errorResponse(
        string $errorCode,
        string $message,
        int $statusCode,
        array $details = []
    ) {
        $response = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'timestamp' => now()->toISOString(),
                'request_id' => request()->header('X-Request-ID', uniqid('req_', true))
            ]
        ];

        if (!empty($details)) {
            $response['error']['details'] = $details;
        }

        // Ajouter des liens d'aide selon le type d'erreur
        $helpLinks = $this->getHelpLinks($errorCode);
        if (!empty($helpLinks)) {
            $response['error']['help'] = $helpLinks;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Obtenir les liens d'aide selon le type d'erreur
     */
    private function getHelpLinks(string $errorCode): array
    {
        return match ($errorCode) {
            'INVALID_API_KEY', 'KEY_NOT_FOUND' => [
                'documentation' => config('app.docs_url') . '/authentication',
                'generate_key' => config('app.dashboard_url') . '/api-keys'
            ],
            'EXPIRED_API_KEY' => [
                'renew_key' => config('app.dashboard_url') . '/api-keys',
                'documentation' => config('app.docs_url') . '/api-keys/renewal'
            ],
            'INSUFFICIENT_PERMISSIONS' => [
                'permissions_guide' => config('app.docs_url') . '/permissions',
                'contact_support' => config('app.support_url')
            ],
            'RATE_LIMIT_EXCEEDED' => [
                'rate_limits' => config('app.docs_url') . '/rate-limits',
                'upgrade_plan' => config('app.dashboard_url') . '/billing'
            ],
            default => []
        };
    }
}
