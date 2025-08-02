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
     *     summary="Liste des transactions",
     *     description="Récupère la liste paginée des transactions avec filtres",
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Numéro de page",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query", 
     *         description="Nombre d'éléments par page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15, maximum=100)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrer par statut",
     *         required=false,
     *         @OA\Schema(type="string", enum={"FAILED", "CANCELLED", "EXPIRED", "SUCCESSFULL"})
     *     ),
     *     @OA\Parameter(
     *         name="transaction_type",
     *         in="query",
     *         description="Filtrer par type",
     *         required=false,
     *         @OA\Schema(type="string", enum={"deposit", "withdrawal"})
     *     ),
     *     @OA\Parameter(
     *         name="customer_phone",
     *         in="query",
     *         description="Filtrer par téléphone client",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Date de début (Y-m-d)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Date de fin (Y-m-d)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Liste des transactions",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/Transaction")
     *             ),
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="last_page", type="integer"),
     *             @OA\Property(property="per_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {

             $authServiceUrl = config('services.services_user.url');

            try {

               return $user = $this->httpClient->get($request, $authServiceUrl, '/api/users', ['read:users']);

            } catch (Exception $e) {
                return response()->json([
                    'error' => 'Utilisateur non trouvé',
                    'message' => $e->getMessage()
                ], 404);
            }


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
     *     description="Crée une nouvelle transaction de dépôt ou retrait",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"entreprise_id", "wallet_id", "operator_id", "transaction_type", "amount", "currency_code", "customer_phone"},
     *             @OA\Property(property="entreprise_id", type="string", format="uuid"),
     *             @OA\Property(property="wallet_id", type="string", format="uuid"),
     *             @OA\Property(property="operator_id", type="string", format="uuid"),
     *             @OA\Property(property="user_id", type="string", format="uuid", nullable=true),
     *             @OA\Property(property="transaction_type", type="string", enum={"deposit", "withdrawal"}),
     *             @OA\Property(property="amount", type="number", format="decimal", minimum=1),
     *             @OA\Property(property="currency_code", type="string", minLength=3, maxLength=3, example="XAF"),
     *             @OA\Property(property="customer_phone", type="string", example="237600000000"),
     *             @OA\Property(property="customer_name", type="string", nullable=true),
     *             @OA\Property(property="api_key_used", type="string"),
     *             @OA\Property(property="metadata", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Transaction créée avec succès",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/Transaction")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Erreur de validation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function store(StoreTransactionRequest $request)
    {

        
        try {
            $request->validated();

              // Créer la transaction
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
