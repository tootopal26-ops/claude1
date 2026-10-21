<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class MeetsMinimumAge implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $minAge = (int) config('minimax.min_age', 18);
        $latestEligibleDob = Carbon::today()->subYears($minAge);

        if (Carbon::parse($value)->greaterThan($latestEligibleDob)) {
            $fail("You must be at least {$minAge} years old to register.");
        }
    }
}
