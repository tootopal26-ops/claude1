<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Settings\PlatformSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The Admin-controlled values the app needs at runtime that are not part of any
 * other resource. Only player-facing values are returned here — never a secret,
 * never an Admin-only setting.
 */
class AppConfigController extends Controller
{
    public function show(PlatformSettingsService $settings): JsonResponse
    {
        return response()->json([
            'data' => [
                'support_url' => $settings->get('support_url'),
            ],
        ]);
    }
}
