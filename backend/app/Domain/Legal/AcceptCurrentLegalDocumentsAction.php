<?php

namespace App\Domain\Legal;

use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records a user's acceptance of every currently-published legal
 * document (one per type — terms_and_conditions, privacy_policy).
 * Idempotent: a document already accepted is left alone (the unique
 * constraint is the dedup mechanism), so this is safe to call on every
 * registration and every re-acceptance check alike.
 */
class AcceptCurrentLegalDocumentsAction
{
    public function execute(User $user, ?string $ipAddress = null): void
    {
        $newlyAccepted = [];

        foreach (LegalDocument::where('is_current', true)->get() as $document) {
            try {
                LegalAcceptance::create([
                    'user_id' => $user->id,
                    'legal_document_id' => $document->id,
                    'accepted_at' => now(),
                    'ip_address' => $ipAddress,
                ]);

                $newlyAccepted[] = $document->id;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        if ($newlyAccepted !== []) {
            AuditLog::record('legal.accepted', $user, null, ['legal_document_ids' => $newlyAccepted], $ipAddress);
        }
    }
}
