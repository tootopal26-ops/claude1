<?php

use App\Http\Controllers\Api\AlphaUpiWebhookController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\DevicePushTokenController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TournamentController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/alpha-upi', AlphaUpiWebhookController::class)->middleware('throttle:60,1');

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/tournaments', [TournamentController::class, 'index']);
        Route::get('/tournaments/{tournament}', [TournamentController::class, 'show']);
        Route::post('/tournaments/{tournament}/join', [TournamentController::class, 'join']);
        Route::get('/tournaments/{tournament}/credentials', [TournamentController::class, 'credentials']);

        Route::post('/devices/push-token', [DevicePushTokenController::class, 'store']);
        Route::delete('/devices/push-token', [DevicePushTokenController::class, 'destroy']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/{tokenId}', [SessionController::class, 'destroy']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/ledger', [WalletController::class, 'ledger']);

        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);

        Route::get('/deposits', [DepositController::class, 'index']);
        Route::post('/deposits', [DepositController::class, 'store']);
        Route::get('/deposits/{deposit}', [DepositController::class, 'show']);

        Route::get('/leaderboards/{type}', [LeaderboardController::class, 'show']);
    });
});
