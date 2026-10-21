<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects a date of birth whose day/month (any year) falls within the
 * configured forward window from today, inclusive of both boundary days.
 *
 * The window is computed by walking real calendar days forward from
 * today (Carbon handles month/year rollover and leap-year Feb 29
 * correctly), then comparing the submitted DOB's day/month against that
 * set — so a Feb 29 birthday is only caught when the window genuinely
 * passes through a real Feb 29 this year.
 */
class DobNotInExclusionWindow implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $dob = Carbon::parse($value);
        $today = Carbon::today();
        $windowDays = (int) config('minimax.dob_exclusion_window_days', 6);

        $excludedMonthDays = collect(range(0, $windowDays))
            ->map(fn (int $offset) => $today->copy()->addDays($offset)->format('m-d'));

        if ($excludedMonthDays->contains($dob->format('m-d'))) {
            $fail('That date of birth cannot be used right now. Please double-check the date and try again.');
        }
    }
}
