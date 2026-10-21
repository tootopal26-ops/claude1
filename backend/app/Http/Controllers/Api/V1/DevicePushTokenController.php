<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterPushTokenRequest;
use App\Models\DevicePushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePushTokenController extends Controller
{
    public function store(RegisterPushTokenRequest $request): JsonResponse
    {
        // A token belongs to a device, not a user — if it's re-registered
        // under a different account (e.g. a shared device, a logout then a
        // different account logging in), it must move to the new owner
        // rather than erroring on the unique constraint.
        DevicePushToken::updateOrCreate(
            ['token' => $request->string('token')->toString()],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->string('platform')->toString() ?: 'android',
                'device_name' => $request->string('device_name')->toString() ?: null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['data' => ['message' => 'Device token registered.']], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        DevicePushToken::where('token', $request->string('token'))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['data' => ['message' => 'Device token removed.']]);
    }
}
