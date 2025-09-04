<?php

namespace App\Http\Controllers;

use App\Helpers\InternalHttpClient;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Services\OperatorService;
use App\Http\Services\RemboursementService;
use App\Models\Transaction;
use App\Http\Services\TransactionService;
use App\Jobs\CheckPaymentStatusJob;
use App\Jobs\CheckTransactionStatusJobs;
use App\Models\CommissionSetting;
use App\Models\PaymentJobs;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class TransactionController extends Controller
{



    private InternalHttpClient $httpClient;
    private TransactionService $transactionService;

    public function __construct()
    {
        // Récupérer le token depuis la requête courante
        $bearerToken = request()->bearerToken();
        $this->httpClient = new InternalHttpClient($bearerToken);
        // $this->transactionService = $transactionService;
    }


    /**
     * @OA\Get(
     *     path="/api/transactions/operations",
     *     tags={"Transactions"},
     *     summary="Lister les transactions",
     *     @OA\Parameter(ref="#/components/parameters/X-API-Public-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Private-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-UUID"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Environment"),
     *     description="Récupère une liste paginée des transactions avec filtres facultatifs : statut, type, téléphone client, dates, pagination.",
     *     operationId="getTransactions",
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="Statut de la transaction (ex: pending, success, failed)",
     *         @OA\Schema(type="string", example="success")
     *     ),
     *     @OA\Parameter(
     *         name="transaction_type",
     *         in="query",
     *         required=false,
     *         description="Type de transaction (deposit ou withdrawal)",
     *         @OA\Schema(type="string", enum={"deposit", "withdrawal"}, example="deposit")
     *     ),
     *     @OA\Parameter(
     *         name="customer_phone",
     *         in="query",
     *         required=false,
     *         description="Numéro de téléphone du client",
     *         @OA\Schema(type="string", example="670000000")
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         required=false,
     *         description="Date de début (format: YYYY-MM-DD)",
     *         @OA\Schema(type="string", format="date", example="2025-07-01")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         required=false,
     *         description="Date de fin (format: YYYY-MM-DD)",
     *         @OA\Schema(type="string", format="date", example="2025-07-29")
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         required=false,
     *         description="Nombre d'éléments par page (max: 100)",
     *         @OA\Schema(type="integer", example=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Liste paginée des transactions",
     *         @OA\JsonContent(
     *             @OA\Property(property="current_page", type="integer", example=1),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="string", format="uuid", example="e1c18c7c-86d1-4bd0-a6a4-bc889e37aacf"),
     *                 @OA\Property(property="entreprise_id", type="string", format="uuid"),
     *                 @OA\Property(property="wallet_id", type="string", format="uuid"),
     *                 @OA\Property(property="operator_id", type="string", format="uuid"),
     *                 @OA\Property(property="amount", type="number", format="float", example=2000),
     *                 @OA\Property(property="net_amount", type="number", format="float", example=1950),
     *                 @OA\Property(property="status", type="string", example="success"),
     *                 @OA\Property(property="transaction_type", type="string", example="deposit"),
     *                 @OA\Property(property="customer_phone", type="string", example="670000000"),
     *                 @OA\Property(property="currency_code", type="string", example="XAF"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-07-29T14:45:00Z")
     *             )),
     *             @OA\Property(property="total", type="integer", example=50),
     *             @OA\Property(property="last_page", type="integer", example=5),
     *             @OA\Property(property="per_page", type="integer", example=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Erreur lors de la récupération des transactions"
     *     )
     * )
     */

    public function index(Request $request)
    {

        $query = Transaction::with('wallet');

        // Filtres
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('transaction_type')) {
            $query->byType($request->transaction_type);
        }

        if ($request->filled('customer_phone')) {
            $query->byCustomer($request->customer_phone);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * @OA\Post(
     *     path="/api/transactions/operations",
     *     tags={"Transactions"},
     *     security={{"bearerAuth":{}}},
     *     summary="Créer une nouvelle transaction",
     *     @OA\Parameter(ref="#/components/parameters/X-API-Public-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Private-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-UUID"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Environment"),
     *     description="Permet de créer une transaction (dépôt ou retrait) pour une entreprise via un opérateur mobile.",
     *     operationId="storeTransaction",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent( 
     *             required={"entreprise_id", "operator_code", "transaction_type", "amount", "customer_phone"},
     *             @OA\Property(property="entreprise_id", type="string", format="uuid", example="c6a3eb17-1234-4db8-8a5b-7c9db62f0ee1"),
     *             @OA\Property(property="operator_code", type="string", example="mtn_cm"),
     *             @OA\Property(property="webhook_url", type="string", example="https://example.com"),
     *             @OA\Property(property="transaction_type", type="string", enum={"deposit", "withdrawal"}, example="deposit"),
     *             @OA\Property(property="amount", type="number", format="float", example=2500.75),
     *             @OA\Property(property="customer_phone", type="string", example="670000000"),
     *             @OA\Property(property="customer_name", type="string", nullable=true, example="Jean Dupont"),
     *             @OA\Property(property="metadata", type="object", nullable=true, example={"order_id": "12345", "description": "Paiement facture"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Transaction créée avec succès",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Transaction créée avec succès"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="transaction_id", type="string", format="uuid", example="acde3bb0-1d2e-4c9b-bd40-90df0d02d7e3"),
     *                 @OA\Property(property="status", type="string", example="pending"),
     *                 @OA\Property(property="amount", type="number", format="float", example=2500.75),
     *                 @OA\Property(property="net_amount", type="number", format="float", example=2450.75),
     *                 @OA\Property(property="currency", type="string", example="XAF"),
     *                 @OA\Property(property="reference", type="string", example="TX123456789"),
     *                 @OA\Property(property="wallet_balance", type="number", format="float", example=10500.00),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-07-29T14:45:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Erreur de validation des données"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Erreur serveur lors de la création"
     *     )
     * )
     */

    public function store(StoreTransactionRequest $request)
    {


        try {
            $request->validated();

            $transactionService = new TransactionService();
            return $result = $transactionService->createTransaction($request, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Transaction créée avec succès',
                'data' => [
                    'transaction_id' => $result['transaction']->id,
                    'status' => $result['transaction']->status,
                    'amount' => $result['transaction']->amount,
                    'net_amount' => $result['transaction']->net_amount,
                    'currency' => $result['transaction']->currency_code,
                    'reference' => $result['wallet_movement']->reference,
                    'wallet_balance' => $result['wallet']->balance,
                    'created_at' => $result['transaction']->created_at
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * @OA\Get(
     *     path="/api/transactions/operations/{id}",
     *     tags={"Transactions"},
     *     summary="Détails d'une transaction",
     *     @OA\Parameter(ref="#/components/parameters/X-API-Public-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Private-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-UUID"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Environment"),
     *     description="Récupère les détails d'une transaction spécifique",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID de la transaction",
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Détails de la transaction",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Transaction")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Transaction non trouvée",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $transaction->load(['wallet', 'operator'])
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/transactions/operations/{id}",
     *     tags={"Transactions"},
     *     summary="Mettre à jour une transaction",
     *     @OA\Parameter(ref="#/components/parameters/X-API-Public-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Private-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-UUID"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Environment"),
     *     description="Met à jour les informations d'une transaction",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID de la transaction",
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"FAILED", "CANCELLED", "EXPIRED", "SUCCESSFULL"}),
     *             @OA\Property(property="operator_status", type="string"),
     *             @OA\Property(property="operator_transaction_id", type="string"),
     *             @OA\Property(property="completed_at", type="string", format="datetime"),
     *             @OA\Property(property="failure_reason", type="string"),
     *             @OA\Property(property="metadata", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transaction mise à jour",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/Transaction")
     *         )
     *     )
     * )
     */
    public function update(StoreTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Si le statut passe à SUCCESSFULL, mettre completed_at
            if (isset($validated['status']) && $validated['status'] === 'SUCCESSFULL' && !$transaction->completed_at) {
                $validated['completed_at'] = now();
            }

            $transaction->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Transaction mise à jour avec succès',
                'data' => $transaction->load(['wallet', 'operator'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/transactions/operations/{id}",
     *     tags={"Transactions"},
     *     summary="Supprimer une transaction",
     *     @OA\Parameter(ref="#/components/parameters/X-API-Public-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Private-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-UUID"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Environment"),
     *     description="Supprime une transaction (soft delete recommandé)",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID de la transaction",
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transaction supprimée",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Suppression interdite",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function destroy(Transaction $transaction): JsonResponse
    {
        try {
            // Vérifier si la transaction peut être supprimée
            if ($transaction->status === 'SUCCESSFULL') {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer une transaction réussie'
                ], 409);
            }

            $transaction->delete();

            return response()->json([
                'success' => true,
                'message' => 'Transaction supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/transactions/operations/status/{status}",
     *     tags={"Transactions"},
     *     summary="Transactions par statut",
     *     description="Récupère les transactions d'un statut donné",
     *     @OA\Parameter(
     *         name="status",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", enum={"FAILED", "CANCELLED", "EXPIRED", "SUCCESSFULL"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Liste des transactions",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Transaction"))
     *     )
     * )
     */
    public function byStatus(Request $request, string $status)
    {
        $transactions = Transaction::byStatus($status)
            ->with(['wallet', 'operator'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($transactions);
    }

    /**
     * @OA\Post(
     *     path="/api/transactions/operations/{id}/retry",
     *     tags={"Transactions"},
     *     summary="Relancer une transaction",
     *     description="Relance une transaction échouée",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transaction relancée",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function retry(Transaction $transaction)
    {
        if (!in_array($transaction->status, ['FAILED', 'CANCELLED'])) {
            return response()->json([
                'success' => false,
                'message' => 'Seules les transactions échouées peuvent être relancées'
            ], 400);
        }

        // Logic to retry transaction
        $transaction->update([
            'status' => 'FAILED',
            'failure_reason' => null,
            'initiated_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaction relancée avec succès'
        ]);
    }


    /**
     * Process refund for a transaction
     * 
     * @OA\Post(
     *     path="/api/transactions/remboursement",
     *     tags={"Transactions"},
     *     summary="Effectuer un remboursement",
     *     @OA\Parameter(ref="#/components/parameters/X-API-Public-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Private-Key"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-UUID"),
     *     @OA\Parameter(ref="#/components/parameters/X-API-Environment"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"transaction_id", "refund_amount", "reason"},
     *             @OA\Property(property="transaction_id", type="string", format="uuid", description="ID de la transaction à rembourser"),
     *             @OA\Property(property="refund_amount", type="number", format="decimal", description="Montant à rembourser"),
     *             @OA\Property(property="reason", type="string", description="Raison du remboursement"),
     *             @OA\Property(property="operator", type="string", enum={"OM", "MOMO"}, description="Opérateur pour le remboursement")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Remboursement effectué avec succès",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="refund_transaction_id", type="string"),
     *             @OA\Property(property="operator_response", type="object")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Données invalides"),
     *     @OA\Response(response=403, description="Solde insuffisant ou transaction non éligible"),
     *     @OA\Response(response=404, description="Transaction non trouvée"),
     *     @OA\Response(response=500, description="Erreur serveur")
     * )
     */
    public function remboursement(Request $request)
    {
        // try {
            // 1. Validation des données d'entrée
            $validated = $request->validate([
                'transaction_id' => 'required|string|uuid',
                'refund_amount' => 'required|numeric|min:0.01',
                'reason' => 'required|string|max:255',
                'operator' => 'required|string|in:OM,MOMO'
            ]);
 
            // 2. Récupérer la transaction originale
            $originalTransaction = Transaction::with('wallet')->find($validated['transaction_id']);

            if (!$originalTransaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée',
                    'error_code' => 'TRANSACTION_NOT_FOUND'
                ], 404);
            }

            // 3. Vérifications de sécurité et business rules
            $securityChecks = $this->validateRefundEligibility($originalTransaction, $validated);
            if (!$securityChecks['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $securityChecks['message'],
                    'error_code' => $securityChecks['error_code']
                ], $securityChecks['status_code']);
            }

            // 4. Vérifier le solde de l'entreprise
            $wallet = $originalTransaction->wallet;
            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Portefeuille de l\'entreprise non trouvé',
                    'error_code' => 'WALLET_NOT_FOUND'
                ], 404);
            }

            // 5. Vérification du solde suffisant
            if ($wallet->balance < $validated['refund_amount']) {

                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant dans le portefeuille de l\'entreprise',
                    'error_code' => 'INSUFFICIENT_BALANCE',
                    'details' => [
                        'current_balance' => $wallet->balance,
                        'requested_amount' => $validated['refund_amount']
                    ]
                ], 403);
            }

            // 6. Vérifier les limites quotidiennes/mensuelles si configurées
            $limitsCheck = $this->checkWalletLimits($wallet, $validated['refund_amount']);
            if (!$limitsCheck['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $limitsCheck['message'],
                    'error_code' => 'LIMIT_EXCEEDED'
                ], 403);
            }

            // 7. Créer l'utilisateur pour le remboursement (basé sur la transaction originale)
            $refundUser = (object) [
                'phone' => $originalTransaction->customer_phone,
                'name' => $originalTransaction->customer_name ?? 'Client',
                'id' => $originalTransaction->user_id
            ];

            // 8. Initier le remboursement via le service
            $remboursementService = new RemboursementService();
            $refundResult = $remboursementService->initiateRemboursement(
                $validated['operator'],
                $refundUser,
                $validated['refund_amount'],
                $originalTransaction->customer_phone
            );

            // // 9. Traiter la réponse du service de remboursement
            // if (!isset($refundResult['success']) || !$refundResult['success']) {

            //     return response()->json([
            //         'success' => false,
            //         'message' => $refundResult['message'] ?? 'Échec du remboursement',
            //         'error_code' => 'REFUND_SERVICE_ERROR',
            //         'details' => $refundResult
            //     ], $refundResult['status'] ?? 500);
            // }

            // 5. Calculer les commissions
            $commissions = $this->calculateCommissions(
                $validated['refund_amount'],
                $validated['operator'],
                'withdrawal'
            );

            // 10. Créer la transaction de remboursement
            $refundTransaction = $this->createRefundTransaction($originalTransaction, $validated, $refundResult, $commissions);

            // 11. Mettre à jour le solde du portefeuille
            $newBalance = $wallet->balance - $validated['refund_amount'];
            $wallet->update(['balance' => $newBalance]);

            // 12. Enregistrer le mouvement de portefeuille
            $this->recordWalletMovement($wallet, $refundTransaction, $validated['refund_amount'], 'debit');

            // 13. Mettre à jour le statut de la transaction originale si remboursement complet
            if ($validated['refund_amount'] >= $originalTransaction->amount) {
                $originalTransaction->update(['status' => 'REFUNDED']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Remboursement effectué avec succès',
                'data' => [
                    'refund_transaction_id' => $refundTransaction->id,
                    'refund_amount' => $validated['refund_amount'],
                    'operator_response' => $refundResult,
                    'new_wallet_balance' => $newBalance,
                    'refund_status' => $refundTransaction->status
                ]
            ]);
        // } catch (\Illuminate\Validation\ValidationException $e) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Données de validation invalides',
        //         'error_code' => 'VALIDATION_ERROR',
        //         'errors' => $e->errors()
        //     ], 422);
        // } catch (Exception $e) {

        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Erreur interne lors du remboursement',
        //         'error_code' => 'INTERNAL_ERROR'
        //     ], 500);
        // }
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
     * Validate if transaction is eligible for refund
     */
    private function validateRefundEligibility($transaction, $validated)
    {
        // Vérifier que la transaction est réussie
        if ($transaction->status !== 'SUCCESSFULL') {
            return [
                'valid' => false,
                'message' => 'Seules les transactions réussies peuvent être remboursées',
                'error_code' => 'TRANSACTION_NOT_SUCCESSFUL',
                'status_code' => 403
            ];
        }

        // Vérifier que le montant du remboursement ne dépasse pas le montant original
        if ($validated['refund_amount'] > $transaction->amount) {
            return [
                'valid' => false,
                'message' => 'Le montant du remboursement ne peut pas dépasser le montant de la transaction originale',
                'error_code' => 'REFUND_AMOUNT_EXCEEDS_ORIGINAL',
                'status_code' => 400
            ];
        }

        // Vérifier que la transaction n'est pas trop ancienne (ex: 30 jours)
        $maxRefundDays = 60;
        if ($transaction->completed_at && $transaction->completed_at->diffInDays(now()) > $maxRefundDays) {
            return [
                'valid' => false,
                'message' => "Les remboursements ne sont autorisés que dans les {$maxRefundDays} jours suivant la transaction",
                'error_code' => 'REFUND_PERIOD_EXPIRED',
                'status_code' => 403
            ];
        }

        // Vérifier qu'il n'y a pas déjà eu de remboursement complet
        if ($transaction->status === 'REFUNDED') {
            return [
                'valid' => false,
                'message' => 'Cette transaction a déjà été remboursée',
                'error_code' => 'ALREADY_REFUNDED',
                'status_code' => 403
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check wallet limits for refund
     */
    private function checkWalletLimits($wallet, $amount)
    {
        // Vérifier les limites quotidiennes si configurées
        if ($wallet->daily_limit) {
            $todayRefunds = Transaction::where('wallet_id', $wallet->id)
                ->where('transaction_type', 'refund')
                ->whereDate('created_at', today())
                ->sum('amount');

            if (($todayRefunds + $amount) > $wallet->daily_limit) {
                return [
                    'valid' => false,
                    'message' => 'Limite quotidienne de remboursement dépassée'
                ];
            }
        }

        // Vérifier les limites mensuelles si configurées
        if ($wallet->monthly_limit) {
            $monthlyRefunds = Transaction::where('wallet_id', $wallet->id)
                ->where('transaction_type', 'refund')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount');

            if (($monthlyRefunds + $amount) > $wallet->monthly_limit) {
                return [
                    'valid' => false,
                    'message' => 'Limite mensuelle de remboursement dépassée'
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Create refund transaction record
     */
    private function createRefundTransaction($originalTransaction, $validated, $refundResult, $commissions)
    {
        return Transaction::create([
            'entreprise_id' => $originalTransaction->entreprise_id,
            'wallet_id' => $originalTransaction->wallet_id,
            'operator_id' => $originalTransaction->operator_id,
            'user_id' => $originalTransaction->user_id,
            'transaction_type' => 'withdrawal',
            'amount' => $validated['refund_amount'],
            'currency_code' => $originalTransaction->currency_code,
            'webhook_url' => $originalTransaction->webhook_url,
            'operator_commission' => $commissions['operator_commission'],
            'internal_commission' => $commissions['internal_commission'],
            'net_amount' => $validated['refund_amount'] - $commissions['operator_commission'] - $commissions['internal_commission'],
            'status' => $refundResult['ResponseMetadata']['HTTPStatusCode'] == 200 ? 'SUCCESSFULL' : 'FAILED',
            'operator_status' => $refundResult['operator_status'] ?? null,
            'operator_transaction_id' => $refundResult['operator_transaction_id'] ?? null,
            'customer_phone' => $originalTransaction->customer_phone,
            'customer_name' => $originalTransaction->customer_name,
            'initiated_at' => now(),
            'payToken' => $refundResult['MessageId'] ?? null,
            'api_key_used' => $originalTransaction->api_key_used,
            'ip_address' => request()->ip() ?? null,    
            'user_agent' => request()->userAgent() ?? null,
            'metadata' => $refundResult ?? null,
            'failure_reason' => $refundResult['ResponseMetadata']['HTTPStatusCode'] == 200 ? null : ($refundResult['message'] ?? 'Échec du remboursement')
        ]);

    }


    /**
     * Record wallet movement for refund
     */
    private function recordWalletMovement($wallet, $transaction, $amount, $type)
    {
        \App\Models\WalletMovement::create([
            'wallet_id' => $wallet->id,
            'transaction_id' => $transaction->id,
            'movement_type' => $type,
            'amount' => $amount,
            'balance_before' => $wallet->balance,
            'balance_after' => $wallet->balance - $amount,
            'description' => "Remboursement vers {$transaction->customer_phone}",
            'reference' => 'REF-' . strtoupper(Str::random(10)),
            'created_by' => $transaction->user_id
        ]);
    }
}
