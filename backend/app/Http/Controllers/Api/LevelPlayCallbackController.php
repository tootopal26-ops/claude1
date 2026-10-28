<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ads\RewardedClaimService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * LevelPlay's rewarded server-to-server callback (the URL set in the LevelPlay dashboard).
 * A valid, signed callback is acknowledged with "{eventId}:OK", the reply LevelPlay expects,
 * including for a duplicate delivery of an event already applied, so it stops retrying.
 * Anything unsigned, forged or stale is refused and logged; it never credits anything.
 */
class LevelPlayCallbackController extends Controller
{
    public function __invoke(Request $request, RewardedClaimService $claims): Response
    {
        $result = $claims->verifyCallback($request->query(), $request->ip());

        if ($result['accepted'] || in_array($result['outcome'], ['unknown_claim', 'expired', 'not_pending', 'event_reused'], true)) {
            // Signed and genuine, so acknowledge it (nothing more will change on a retry).
            return response(($result['event_id'] ?? '').':OK', 200)->header('Content-Type', 'text/plain');
        }

        return response('refused', 403)->header('Content-Type', 'text/plain');
    }
}
