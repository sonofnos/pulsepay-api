<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillPaymentController;
use App\Http\Controllers\Api\P2pOfferController;
use App\Http\Controllers\Api\P2pTradeController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Signature-verified, not session-authenticated: the caller is an external
// payment processor, not a logged-in user.
Route::post('/webhooks/crypto-deposit', [WebhookController::class, 'cryptoDeposit']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/wallets', [WalletController::class, 'index']);
    Route::get('/wallets/{wallet}/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallets/transfer', [WalletController::class, 'transfer'])->middleware('idempotent');

    Route::get('/p2p/offers', [P2pOfferController::class, 'index']);
    Route::post('/p2p/offers', [P2pOfferController::class, 'store']);
    Route::post('/p2p/offers/{offer}/cancel', [P2pOfferController::class, 'cancel']);
    Route::post('/p2p/offers/{offer}/trades', [P2pTradeController::class, 'store'])->middleware('idempotent');

    Route::get('/p2p/trades', [P2pTradeController::class, 'index']);
    Route::post('/p2p/trades/{trade}/confirm-payment', [P2pTradeController::class, 'confirmPayment']);
    Route::post('/p2p/trades/{trade}/release', [P2pTradeController::class, 'release'])->middleware('idempotent');
    Route::post('/p2p/trades/{trade}/cancel', [P2pTradeController::class, 'cancel']);

    Route::get('/bill-payments', [BillPaymentController::class, 'index']);
    Route::post('/bill-payments', [BillPaymentController::class, 'store'])->middleware('idempotent');
});
