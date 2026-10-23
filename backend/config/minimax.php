<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum registration age
    |--------------------------------------------------------------------------
    |
    | A player's date of birth must indicate they are at least this many
    | years old at the time of registration. See blocked_states below for
    | the jurisdiction side of eligibility.
    |
    | This and every other value below marked "Admin-editable" can be
    | overridden at runtime from the platform_settings table — see
    | PlatformSettingsService — without a deploy. The env()-backed value
    | here is only the fallback used until an Admin ever changes it.
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
    | Concurrent wallet activity threshold
    |--------------------------------------------------------------------------
    |
    | A wallet that gets this many ledger entries within this many
    | seconds raises a medium-priority risk alert for review — the row
    | lock in WalletService already makes every operation safe, this is
    | separately about flagging unusually rapid/concurrent activity
    | against one wallet (automated abuse, a race attempt) regardless of
    | whether any individual operation itself failed.
    |
    */
    'concurrent_wallet_op_threshold' => (int) env('MINIMAX_CONCURRENT_WALLET_OP_THRESHOLD', 5),
    'concurrent_wallet_op_window_seconds' => (int) env('MINIMAX_CONCURRENT_WALLET_OP_WINDOW_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Suspicious withdrawal pattern threshold
    |--------------------------------------------------------------------------
    |
    | A user who submits this many withdrawal requests (of any status)
    | within this many hours raises a medium-priority risk alert — rapid
    | repeated cash-out attempts are a common fraud signal worth a second
    | look, independent of whether any individual request is itself
    | valid.
    |
    */
    'suspicious_withdrawal_count_threshold' => (int) env('MINIMAX_SUSPICIOUS_WITHDRAWAL_COUNT_THRESHOLD', 3),
    'suspicious_withdrawal_window_hours' => (int) env('MINIMAX_SUSPICIOUS_WITHDRAWAL_WINDOW_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Blocked states/regions (Admin-editable)
    |--------------------------------------------------------------------------
    |
    | A registration whose submitted state/region is in this list is
    | rejected. Empty by default — deliberately not pre-populated here,
    | since which jurisdictions to block for real-money gaming is a
    | compliance call for the operator to make with their own current
    | legal guidance, not one this codebase makes for them.
    |
    */
    'blocked_states' => [],

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
        // Player-facing artwork. Served from the public disk so the app can load it
        // without a signed-in request; always re-encoded server-side (see
        // App\Domain\Media\ImageVariantProcessor), never stored as uploaded.
        'game_cards' => [
            'label' => 'Tournament game cards',
            'disk' => 'public',
            'max_size_kb' => 5120,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
            'retention_days' => null,
            'view_permission' => 'games.manage',
        ],
        'leaderboard_banners' => [
            'label' => 'Leaderboard banners',
            'disk' => 'public',
            'max_size_kb' => 5120,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
            'retention_days' => null,
            'view_permission' => 'leaderboards.manage',
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

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Where `backup:run` (see docs/backup-restore.md) writes its
    | gzipped database dump and storage/app archive, and how many days
    | of backups it keeps before pruning the oldest. Point this at a
    | mounted volume separate from the app's own disk in production —
    | a backup that lives only on the server it protects doesn't
    | survive that server's own disk failure.
    |
    */
    'backup_path' => env('BACKUP_PATH', storage_path('app/backups')),
    'backup_retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

];
