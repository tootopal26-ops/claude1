<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum registration age
    |--------------------------------------------------------------------------
    |
    | A player's date of birth must indicate they are at least this many
    | years old at the time of registration. Jurisdiction-specific
    | eligibility rules on top of this are a later phase (see the project
    | plan's Phase 15); this is only the baseline age floor.
    |
    */
    'min_age' => (int) env('MINIMAX_MIN_AGE', 18),

    /*
    |--------------------------------------------------------------------------
    | DOB exclusion window (days)
    |--------------------------------------------------------------------------
    |
    | A submitted date of birth may not fall, by day/month only (any
    | birth year), within this many calendar days forward of today,
    | inclusive of both the current day and the final day of the window.
    |
    */
    'dob_exclusion_window_days' => (int) env('MINIMAX_DOB_EXCLUSION_WINDOW_DAYS', 6),

];
