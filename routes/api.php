<?php

use App\Http\Controllers\CommissionSettingController;
use App\Http\Controllers\TransactionController; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('transactions')->group(function () {
    Route::middleware('api_key_auth')->prefix('operations')->group(function () {
        Route::post('/', [TransactionController::class, 'store']);
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/{id}', [TransactionController::class, 'show']);
        Route::put('/{id}', [TransactionController::class, 'update']);
        Route::delete('/{id}', [TransactionController::class, 'destroy']);
    });
    // Route::get('operations/', [TransactionController::class, 'index']);
    Route::get('/wallets/{walletId}/transactions', function ($walletId) {
        return \App\Models\Transaction::where('wallet_id', $walletId)->get();
    });

    Route::prefix('commission-settings')->group(function () {
        Route::get('/', [CommissionSettingController::class, 'index']);
        Route::post('/', [CommissionSettingController::class, 'store']);
        Route::get('/{id}', [CommissionSettingController::class, 'show']);
        Route::put('/{id}', [CommissionSettingController::class, 'update']);
        Route::delete('/{id}', [CommissionSettingController::class, 'destroy']);
    });

    Route::get('getjobs', [TransactionController::class, 'getJobs']);
 
});