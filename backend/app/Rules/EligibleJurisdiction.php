<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects a registration whose submitted state/region is in the
 * Admin-editable blocked_states list (see PlatformSettingsService).
 * Comparison is case-insensitive and trims whitespace, since this is
 * free text typed by an Admin and by a player, not a fixed enum.
 */
class EligibleJurisdiction implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $blockedStates = collect((array) config('minimax.blocked_states', []))
            ->map(fn ($state) => mb_strtolower(trim((string) $state)));

        if ($blockedStates->contains(mb_strtolower(trim((string) $value)))) {
            $fail('Registration is not available from this state/region.');
        }
    }
}
