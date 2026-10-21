<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payments\DepositService;
use App\Domain\Payments\PaymentGatewayException;
use App\Domain\Payments\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated endpoint the gateway calls directly — identity
 * is established purely by the HMAC signature, not a session or token.
 * Reads the raw request body because the signature is computed over the
 * exact bytes the gateway sent, not a re-serialized/normalized copy.
 */
class AlphaUpiWebhookController extends Controller
{
    public function __invoke(Request $request, DepositService $deposits, PaymentGatewayInterface $gateway): JsonResponse
    {
        $signature = $request->header($gateway->webhookSignatureHeader(), '');

        try {
            $deposits->handleWebhook($request->getContent(), $signature);
        } catch (PaymentGatewayException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        return response()->json(['received' => true]);
    }
}
