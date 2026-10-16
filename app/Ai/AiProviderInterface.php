<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One AI vendor's chat-completion call, reduced to the one shape the rest of the application needs: a system instruction, one user
 * message, and a plain-text reply. Business logic (ReportAi, SupportAssistant) never imports a concrete provider — only this interface —
 * so the application keeps working, unchanged, whichever provider an admin picks, or none at all.
 */
interface AiProviderInterface
{
    /** A short, human name for logs and the admin settings screen (never the API key). */
    public function label(): string;

    public function complete(string $system, string $user, int $maxTokens): AiResult;
}
