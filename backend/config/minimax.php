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

    /*
    |--------------------------------------------------------------------------
    | Wallet risk-alert threshold (coins)
    |--------------------------------------------------------------------------
    |
    | A manual wallet adjustment (add or subtract) at or above this amount
    | in a single operation raises a high-priority risk alert for review.
    |
    */
    'wallet_risk_alert_threshold' => env('MINIMAX_WALLET_RISK_ALERT_THRESHOLD', '200.00'),

    /*
    |--------------------------------------------------------------------------
    | Tournament join window (minutes after scheduled start)
    |--------------------------------------------------------------------------
    |
    | Joining a tournament stays open until this many minutes after its
    | scheduled_start_at (the "lobby" time, not the moment the match itself
    | begins), or until credentials are released — whichever happens first.
    |
    */
    'tournament_join_window_minutes' => (int) env('MINIMAX_TOURNAMENT_JOIN_WINDOW_MINUTES', 4),

    /*
    |--------------------------------------------------------------------------
    | Minimum-players grace period (minutes after scheduled start)
    |--------------------------------------------------------------------------
    |
    | If a tournament with a configured min_players still hasn't reached
    | that many confirmed entries this many minutes after scheduled_start_at,
    | and staff hasn't already released credentials (which overrides this
    | check), it is auto-cancelled and every paid entry is refunded.
    |
    */
    'tournament_min_players_grace_minutes' => (int) env('MINIMAX_TOURNAMENT_MIN_PLAYERS_GRACE_MINUTES', 5),

];
