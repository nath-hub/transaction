<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *     title="Transaction Service API",
 *     version="1.0.0",
 *     description="API de gestion des transactions",
 *     @OA\Contact(
 *         email="n.taffot@elyft.tech"
 *     )
 * )
 * @OA\Server(
 *     url="http://localhost:8003",
 *     description="Transaction Service Server"
 * )
 *   @OA\Components(
 *      @OA\SecurityScheme(
 *          securityScheme="bearerAuth",
 *          type="http",
 *          scheme="bearer",
 *          bearerFormat="JWT"
 *      ),
 *      @OA\Parameter(
 *         parameter="X-API-Public-Key",
 *         name="X-API-Public-Key",
 *         in="header",
 *         required=true,
 *         description="Clé API publique",
 *         @OA\Schema(type="string", example="pk_test_1234567890abcdef")
 *     ),
 *     @OA\Parameter(
 *         parameter="X-API-Private-Key",
 *         name="X-API-Private-Key",
 *         in="header",
 *         required=true,
 *         description="Clé API privée",
 *         @OA\Schema(type="string", example="sk_test_1234567890abcdef")
 *     ),
 *     @OA\Parameter(
 *         parameter="X-API-UUID",
 *         name="X-API-UUID",
 *         in="header",
 *         required=true,
 *         description="UUID de l'entreprise",
 *         @OA\Schema(type="string", format="uuid", example="c6a3eb17-1234-4db8-8a5b-7c9db62f0ee1")
 *     ),
 *     @OA\Parameter(
 *         parameter="X-API-Environment",
 *         name="X-API-Environment",
 *         in="header",
 *         required=true,
 *         description="Environnement d'exécution",
 *         @OA\Schema(type="string", enum={"test", "production"}, example="test")
 *     )
 * )
 * @OA\Security(
 * {"bearerAuth": {}}
 *    )
 * @OA\Parameter(
 *     parameter="AcceptHeader",
 *     name="Accept",
 *     in="header",
 *     required=false,
 *     @OA\Schema(
 *         type="string",
 *         default="application/json"
 *     )
 * )
 * 
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
