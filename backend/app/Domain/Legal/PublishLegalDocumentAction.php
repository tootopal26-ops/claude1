<?php

namespace App\Domain\Legal;

use App\Models\AuditLog;
use App\Models\LegalDocument;
use App\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishing a new version of a legal document type is the only way its
 * content ever changes — past versions are immutable and stay on file
 * for audit/dispute purposes. The new version becomes "current"
 * atomically with the old one losing that status, so there is never a
 * moment with zero or more than one current document of the same type.
 *
 * LegalAcceptance rows are keyed to a specific document version (see its
 * unique(user_id, legal_document_id)), not to a type, so a user's
 * acceptance of the old version never carries forward to the new one —
 * publishing a revision makes every user outstanding for it again.
 */
class PublishLegalDocumentAction
{
    public const array TYPES = ['terms_and_conditions', 'privacy_policy'];

    /**
     * @throws ValidationException
     */
    public function execute(string $type, string $title, string $content, StaffUser $actor): LegalDocument
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => "Unknown legal document type \"{$type}\".",
            ]);
        }

        if (trim($title) === '' || trim($content) === '') {
            throw ValidationException::withMessages([
                'content' => 'A title and content are required.',
            ]);
        }

        return DB::transaction(function () use ($type, $title, $content, $actor) {
            LegalDocument::where('type', $type)->where('is_current', true)->update(['is_current' => false]);

            $nextVersion = (int) LegalDocument::where('type', $type)->max('version') + 1;

            $document = LegalDocument::create([
                'type' => $type,
                'version' => $nextVersion,
                'title' => $title,
                'content' => $content,
                'is_current' => true,
                'published_by_staff_id' => $actor->id,
                'published_at' => now(),
            ]);

            AuditLog::record('legal_document.published', $actor, $document, [
                'type' => $type,
                'version' => $nextVersion,
            ]);

            return $document;
        });
    }
}
