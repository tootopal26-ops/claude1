<?php

use App\Http\Controllers\Api\AlphaUpiWebhookController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\DevicePushTokenController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\LegalController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RedeemController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TournamentController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/alpha-upi', AlphaUpiWebhookController::class)->middleware('throttle:60,1');

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::get('/legal/current', [LegalController::class, 'current']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/legal/acceptance-status', [LegalController::class, 'acceptanceStatus']);
        Route::post('/legal/accept', [LegalController::class, 'accept']);

        Route::get('/games', [GameController::class, 'index']);

        Route::get('/tournaments', [TournamentController::class, 'index']);
        Route::get('/tournaments/{tournament}', [TournamentController::class, 'show']);
        Route::post('/tournaments/{tournament}/join', [TournamentController::class, 'join']);
        Route::get('/tournaments/{tournament}/credentials', [TournamentController::class, 'credentials']);
        Route::get('/tournaments/{tournament}/results', [TournamentController::class, 'results']);

        Route::post('/devices/push-token', [DevicePushTokenController::class, 'store']);
        Route::delete('/devices/push-token', [DevicePushTokenController::class, 'destroy']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/{tokenId}', [SessionController::class, 'destroy']);

        Route::get('/profile/stats', [ProfileController::class, 'stats']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/ledger', [WalletController::class, 'ledger']);

        Route::post('/redeem', [RedeemController::class, 'store'])->middleware('throttle:redeem');

        Route::get('/sections', [SectionController::class, 'index']);
        Route::post('/sections/{key}/unlock', [SectionController::class, 'unlock'])->middleware('throttle:section-unlock');

        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);

        Route::get('/deposits', [DepositController::class, 'index']);
        Route::post('/deposits', [DepositController::class, 'store']);
        Route::get('/deposits/{deposit}', [DepositController::class, 'show']);

        Route::get('/banners/home', [LeaderboardController::class, 'homeBanners']);
        Route::get('/leaderboards/banners', [LeaderboardController::class, 'banners']);
        Route::get('/leaderboards/{type}', [LeaderboardController::class, 'show'])->middleware('section.unlocked:leaderboard');
    });
});
