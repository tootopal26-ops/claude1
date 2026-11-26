<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Legal\AcceptCurrentLegalDocumentsAction;
use App\Domain\Referrals\ReferralService;
use App\Domain\Security\RecordLoginAttemptAction;
use App\Domain\Security\ResolveUserByIdentifierAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Referral;
use App\Models\User;
use App\Support\LoginIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(
        RegisterRequest $request,
        AcceptCurrentLegalDocumentsAction $acceptLegalDocuments,
        ReferralService $referrals,
    ): JsonResponse {
        $attributes = collect($request->validated())->except(['accept_terms', 'referral_code'])->all();
        $referralCode = $request->validated()['referral_code'] ?? null;

        [$user, $referral] = DB::transaction(function () use ($attributes, $request, $acceptLegalDocuments, $referrals, $referralCode) {
            $user = User::create($attributes);

            $acceptLegalDocuments->execute($user, $request->ip());

            // Lets a genuinely invalid/ineligible code roll back the whole registration
            // with a specific error, rather than silently dropping it — a null/empty code,
            // or the referral system being off, is always a safe no-op (see
            // ReferralService::applyReferral).
            $referral = $referrals->applyReferral($user, $referralCode);

            return [$user, $referral];
        });

        $token = $user->createToken($request->userAgent() ?? 'mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
                'referral' => $referral instanceof Referral ? [
                    'new_player_reward' => (string) $referral->new_player_reward,
                ] : null,
            ],
        ], 201);
    }

    public function login(
        LoginRequest $request,
        ResolveUserByIdentifierAction $resolveUser,
        RecordLoginAttemptAction $recordLoginAttempt,
    ): JsonResponse {
        $errorKey = $request->errorKey();
        $identifier = LoginIdentifier::canonicalize($request->identifierValue());

        $user = $resolveUser->execute($identifier);

        if (! $user || ! Hash::check((string) $request->string('password'), $user->password)) {
            $recordLoginAttempt->execute($identifier, $request->ip(), false);

            throw ValidationException::withMessages([
                $errorKey => 'These credentials do not match our records.',
            ]);
        }

        if ($user->is_banned) {
            $recordLoginAttempt->execute($identifier, $request->ip(), false);

            throw ValidationException::withMessages([
                $errorKey => 'This account has been suspended.',
            ]);
        }

        $recordLoginAttempt->execute($identifier, $request->ip(), true);

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
