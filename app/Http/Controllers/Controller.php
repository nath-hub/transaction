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
 * @OA\SecurityScheme(
 * securityScheme="bearerAuth",
 * type="http",
 * scheme="bearer",
 * bearerFormat="JWT"
 * )
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
