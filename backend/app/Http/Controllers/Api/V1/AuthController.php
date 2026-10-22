<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Legal\AcceptCurrentLegalDocumentsAction;
use App\Domain\Security\RecordLoginAttemptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AcceptCurrentLegalDocumentsAction $acceptLegalDocuments): JsonResponse
    {
        $attributes = collect($request->validated())->except('accept_terms')->all();

        $user = DB::transaction(function () use ($attributes, $request, $acceptLegalDocuments) {
            $user = User::create($attributes);

            $acceptLegalDocuments->execute($user, $request->ip());

            return $user;
        });

        $token = $user->createToken($request->userAgent() ?? 'mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    public function login(LoginRequest $request, RecordLoginAttemptAction $recordLoginAttempt): JsonResponse
    {
        $mobile = (string) $request->string('mobile');
        $user = User::where('mobile', $mobile)->first();

        if (! $user || ! Auth::guard('web')->validate([
            'mobile' => $request->string('mobile'),
            'password' => $request->string('password'),
        ])) {
            $recordLoginAttempt->execute($mobile, $request->ip(), false);

            throw ValidationException::withMessages([
                'mobile' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->is_banned) {
            $recordLoginAttempt->execute($mobile, $request->ip(), false);

            throw ValidationException::withMessages([
                'mobile' => 'This account has been suspended.',
            ]);
        }

        $recordLoginAttempt->execute($mobile, $request->ip(), true);

        $token = $user->createToken($request->userAgent() ?? 'mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }
}
