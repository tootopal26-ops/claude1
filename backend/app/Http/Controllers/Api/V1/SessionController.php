<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->orderByDesc('last_used_at')->get();

        return response()->json(['data' => SessionResource::collection($tokens)]);
    }

    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->where('id', $tokenId)->first();

        if ($token === null) {
            abort(404);
        }

        $isCurrent = $request->user()->currentAccessToken()?->id === $token->id;

        $token->delete();

        return response()->json([
            'data' => [
                'message' => $isCurrent
                    ? 'This session has been revoked; you are now logged out.'
                    : 'That device has been logged out.',
                'revoked_current_session' => $isCurrent,
            ],
        ]);
    }
}
