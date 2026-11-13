<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\DepositBonusService;
use App\Domain\Settings\PlatformSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Admin-controlled values the app needs at runtime that are not part of any
 * other resource. Only player-facing values are returned here — never a secret,
 * never an Admin-only setting.
 */
class AppConfigController extends Controller
{
    public function show(Request $request, PlatformSettingsService $settings, DepositBonusService $depositBonus): JsonResponse
    {
        return response()->json([
            'data' => [
                'support_url' => $settings->get('support_url'),
                // "eligible" is a best-effort read for the Deposit screen's display only —
                // the backend's own locked-transaction check inside DepositBonusService's
                // maybeAward() is the only thing that actually decides whether a bonus is
                // credited; the app never guesses or computes eligibility itself.
                'deposit_bonus' => $depositBonus->eligibilityFor($request->user()),
            ],
        ]);
    }
}
