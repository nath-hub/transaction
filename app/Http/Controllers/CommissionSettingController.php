<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommissionSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CommissionSettingController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/transactions/commission-settings",
     *     tags={"Commission Settings"},
     *     summary="Lister toutes les commissions",
     *     @OA\Response(response=200, description="Liste des commissions"),
     *     @OA\Response(
     *         response=400,
     *         description="Requête invalide",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Requête invalide")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Non authentifié",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Non authentifié")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Accès refusé",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Accès refusé"),
     *             @OA\Property(property="status", type="integer", example=403),
     *             @OA\Property(property="code", type="string", example="PERMISSION_DENIED")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Ressource non trouvée",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ressource non trouvée")
     *         )
     *     )
     * )
     */
    public function index()
    {
        return response()->json(CommissionSetting::all());
    }


    /**
     * @OA\Post(
     *     path="/api/transactions/commission-settings",
     *     tags={"Commission Settings"},
     *     summary="Créer une nouvelle règle de commission",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="entreprise_id", type="string", format="uuid"),
     *                 @OA\Property(property="country_id", type="string", format="uuid"),
     *                 @OA\Property(property="operator_id", type="string", format="uuid"),
     *                 @OA\Property(property="transaction_type", type="string", enum={"deposit", "withdrawal"}),
     *                 @OA\Property(property="commission_type", type="string", enum={"percentage", "fixed"}),
     *                 @OA\Property(property="commission_value", type="number", format="float"),
     *                 @OA\Property(property="min_amount", type="number", format="float"),
     *                 @OA\Property(property="max_amount", type="number", format="float"),
     *                 @OA\Property(property="is_active", type="boolean"),
     *                 @OA\Property(property="created_by", type="string", format="uuid")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Créé avec succès"),
     *     @OA\Response(
     *         response=400,
     *         description="Requête invalide",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Requête invalide")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Non authentifié",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Non authentifié")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Accès refusé",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Accès refusé"),
     *             @OA\Property(property="status", type="integer", example=403),
     *             @OA\Property(property="code", type="string", example="PERMISSION_DENIED")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Ressource non trouvée",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ressource non trouvée")
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'entreprise_id' => 'required|uuid|exists:entreprises,id',
            'country_id' => 'required|uuid|exists:countries,id',
            'operator_id' => 'required|uuid|exists:operators,id',
            'transaction_type' => 'required|in:deposit,withdrawal',
            'commission_type' => 'nullable|in:percentage,fixed',
            'commission_value' => 'required|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|gte:min_amount',
            'is_active' => 'boolean', 
        ]);
        $data['id'] = (string) Str::uuid();
        if ($data['is_active']) {
            $data['is_active'] = 1;
        } else {
            $data['is_active'] = 0;
        }

        $data['created_by'] = auth()->user()->id;

        $commission = CommissionSetting::create($data);
        return response()->json($commission, 201);
    }


    /**
     * @OA\Get(
     *     path="/api/transactions/commission-settings/{id}",
     *     tags={"Commission Settings"},
     *     summary="Afficher une commission spécifique",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(response=200, description="Détails de la commission"),
     *     @OA\Response(
     *         response=400,
     *         description="Requête invalide",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Requête invalide")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Non authentifié",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Non authentifié")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Accès refusé",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Accès refusé"),
     *             @OA\Property(property="status", type="integer", example=403),
     *             @OA\Property(property="code", type="string", example="PERMISSION_DENIED")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Ressource non trouvée",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ressource non trouvée")
     *         )
     *     )
     * )
     */
    public function show(string $id)
    {
        $commission = CommissionSetting::findOrFail($id);
        return response()->json($commission);
    }

    /**
     * @OA\Put(
     *     path="/api/transactions/commission-settings/{id}",
     *     tags={"Commission Settings"},
     *     summary="Mettre à jour une commission",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(property="entreprise_id", type="string"),
     *                 @OA\Property(property="country_id", type="string"),
     *                 @OA\Property(property="operator_id", type="string"),
     *                 @OA\Property(property="transaction_type", type="string"),
     *                 @OA\Property(property="commission_type", type="string"),
     *                 @OA\Property(property="commission_value", type="number"),
     *                 @OA\Property(property="min_amount", type="number"),
     *                 @OA\Property(property="max_amount", type="number"),
     *                 @OA\Property(property="is_active", type="boolean"),
     *                 @OA\Property(property="created_by", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Mise à jour réussie"),
     *     @OA\Response(
     *         response=400,
     *         description="Requête invalide",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Requête invalide")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Non authentifié",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Non authentifié")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Accès refusé",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Accès refusé"),
     *             @OA\Property(property="status", type="integer", example=403),
     *             @OA\Property(property="code", type="string", example="PERMISSION_DENIED")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Ressource non trouvée",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ressource non trouvée")
     *         )
     *     )
     * )
     */
    public function update(Request $request, string $id)
    {
        $commission = CommissionSetting::findOrFail($id);
        $commission->update($request->all());
        return response()->json($commission);
    }


    /**
     * @OA\Delete(
     *     path="/api/transactions/commission-settings/{id}",
     *     tags={"Commission Settings"},
     *     summary="Supprimer une commission",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(response=204, description="Supprimé avec succès"),
     *     @OA\Response(
     *         response=401,
     *         description="Non authentifié",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Non authentifié")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Accès refusé",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Accès refusé"),
     *             @OA\Property(property="status", type="integer", example=403),
     *             @OA\Property(property="code", type="string", example="PERMISSION_DENIED")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Ressource non trouvée",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ressource non trouvée")
     *         )
     *     )
     * )
     */
    public function destroy(string $id)
    {
        $commission = CommissionSetting::findOrFail($id);
        $commission->delete();
        return response()->json(null, 204);
    }
}
