<?php

namespace App\Http\Controllers;

use App\Helpers\InternalHttpClient;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Http\Services\TransactionService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $result = $transactionService->createTransaction($request, $request->all());

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
            'status' => 'FAILED', // Reset to pending
            'failure_reason' => null,
            'initiated_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaction relancée avec succès'
        ]);
    }
}
