<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Legal\AcceptCurrentLegalDocumentsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\LegalDocumentResource;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalController extends Controller
{
    /**
     * Public: every currently-published document (one per type), so the
     * app can show these before a player has even registered.
     */
    public function current(): JsonResponse
    {
        return response()->json([
            'data' => LegalDocumentResource::collection(LegalDocument::where('is_current', true)->get()),
        ]);
    }

    /**
     * Which currently-published documents this player hasn't accepted
     * yet — empty right after registration, non-empty again the moment
     * staff publishes a revision to one they'd already accepted.
     */
    public function acceptanceStatus(Request $request): JsonResponse
    {
        $current = LegalDocument::where('is_current', true)->get();

        $acceptedDocumentIds = LegalAcceptance::where('user_id', $request->user()->id)
            ->whereIn('legal_document_id', $current->pluck('id'))
            ->pluck('legal_document_id');

        $outstanding = $current->whereNotIn('id', $acceptedDocumentIds)->values();

        return response()->json([
            'data' => [
                'needs_acceptance' => $outstanding->isNotEmpty(),
                'outstanding' => LegalDocumentResource::collection($outstanding),
            ],
        ]);
    }

    public function accept(Request $request, AcceptCurrentLegalDocumentsAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->ip());

        return response()->json(['data' => ['accepted' => true]]);
    }
}
