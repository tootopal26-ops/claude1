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

    /*
    |--------------------------------------------------------------------------
    | Leaderboard reward payouts (coins)
    |--------------------------------------------------------------------------
    |
    | Credited to eligible winnings exactly once per period, when that
    | period finalizes, keyed by period type then final rank. A rank with
    | no entry here receives no reward. Change freely — only the periods
    | finalized AFTER a change pick up the new amounts.
    |
    */
    'leaderboard_rewards' => [
        'daily' => [1 => '50.00', 2 => '30.00', 3 => '20.00'],
        'weekly' => [1 => '300.00', 2 => '150.00', 3 => '100.00'],
        'monthly' => [1 => '1000.00', 2 => '500.00', 3 => '250.00'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage buckets
    |--------------------------------------------------------------------------
    |
    | Every uploaded file belongs to exactly one of these categories.
    | allowed_mime_types is checked against the file's actual detected
    | content type, never the client-supplied one or its extension.
    | retention_days is how long an asset may live before storage:cleanup
    | is allowed to remove it — null means it's never auto-removed. Either
    | way, an asset with a retained_reason, or tied to the subject of an
    | open (unreviewed) risk alert, is never removed automatically.
    |
    */
    'storage_buckets' => [
        'tournament_evidence' => [
            'label' => 'Tournament result evidence',
            'max_size_kb' => 8192,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
            'retention_days' => 180,
            'view_permission' => 'results.upload_attachments',
        ],
        'withdrawal_proof' => [
            'label' => 'Withdrawal payout proof',
            'max_size_kb' => 4096,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            'retention_days' => 365,
            'view_permission' => 'withdrawals.manage',
        ],
        'staff_documents' => [
            'label' => 'Internal staff documents',
            'max_size_kb' => 10240,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            'retention_days' => null,
            'view_permission' => 'storage.manage',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage quota (bytes per bucket)
    |--------------------------------------------------------------------------
    |
    | Soft limit shown on the Admin storage dashboard as a percentage-used
    | bar per bucket. Advisory only — uploads are never blocked by it.
    |
    */
    'storage_quota_bytes_per_bucket' => (int) env('MINIMAX_STORAGE_QUOTA_BYTES_PER_BUCKET', 5 * 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Theme tokens
    |--------------------------------------------------------------------------
    |
    | The key is the data-theme / data-font attribute value applied to
    | <html> (see resources/css/themes.css for the actual CSS custom
    | property values, and resources/js/app.js for the switcher). Mobile
    | defines the equivalent palettes independently in
    | mobile/src/theme/tokens.ts — keep the key list and default in sync
    | by hand if either side's list changes.
    |
    */
    'theme_colors' => [
        'purple-glass' => 'Purple Glassmorphism',
        'midnight-emerald' => 'Midnight Emerald',
        'crimson-arena' => 'Crimson Arena',
        'cyber-blue' => 'Cyber Blue',
        'sunset-gold' => 'Sunset Gold',
        'royal-indigo' => 'Royal Indigo',
        'monochrome-steel' => 'Monochrome Steel',
    ],

    'theme_fonts' => [
        'default-sans' => 'Default Sans',
        'modern-grotesk' => 'Modern Grotesk',
        'rounded-friendly' => 'Rounded Friendly',
        'classic-serif' => 'Classic Serif',
        'technical-mono' => 'Technical Mono',
        'condensed-impact' => 'Condensed Impact',
        'elegant-display' => 'Elegant Display',
    ],

];
