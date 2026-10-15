<?php
/**
 * Default catalogue (from the product specification). Prices are in PAISE.
 * Everything here is editable in Admin → Services; the seeder only inserts rows
 * that do not exist yet, so re-running it never overwrites admin edits.
 *
 * The red TRIAL PACK badge is NOT assigned here: the storefront derives it from the price
 * (every service sold at exactly 5.00 INR gets it — see App\Services\Tags). The `tag` column below is the
 * code of an optional tag from the 10 predefined ones (App\Services\Tags::PREDEFINED); null = no tag.
 *
 * All services start in MANUAL mode so a fresh install never tries to call an
 * SMM provider that has not been configured. Switch a service to AUTO (and set
 * its SMM service ID + cost) once Admin → Settings → SMM API is connected.
 */

return [
    'platforms' => [
        ['slug' => 'instagram', 'name' => 'Instagram', 'tagline' => 'Followers · Likes · Views · Blue Tick', 'sort_order' => 10],
        ['slug' => 'youtube',   'name' => 'YouTube',   'tagline' => 'Subscribers · Likes · Views · Watch Time', 'sort_order' => 20],
    ],

    // code, platform, type, target_kind, title, subtitle, price_paise, quantity, tag (code of a service tag, see service_tags), premium, sort
    'services' => [
        // ── Instagram · Followers ──
        ['ig-followers-50',   'instagram', 'followers', 'profile', '50 Followers',   'Instagram Non Drop Followers', 500,   50,      null, null, 0, 10],
        ['ig-followers-100k', 'instagram', 'followers', 'profile', '100K Followers', 'Instagram Non Drop Followers', 9900,  100000,  null, null, 0, 20],
        // ── Instagram · Likes ──
        ['ig-likes-100',      'instagram', 'likes',     'post',    '100 Likes',      'Instagram Post Likes',         500,   100,     null, null, 0, 30],
        ['ig-likes-50k',      'instagram', 'likes',     'post',    '50K Likes',      'Instagram Post Likes',         2500,  50000,   null, null, 0, 40],
        // ── Instagram · Views ──
        ['ig-views-1k',       'instagram', 'views',     'post',    '1,000 Views',    'Instagram Reel & Video Views', 500,   1000,    null, null, 0, 50],
        ['ig-views-1m',       'instagram', 'views',     'post',    '1 Million Views', 'Instagram Reel & Video Views', 9900, 1000000, null, 0, 60],
        // ── Instagram · Verification ──
        ['ig-blue-tick',      'instagram', 'verification', 'profile', 'Permanent Blue Tick', 'Instagram Verification Service', 19900, 1, null, 0, 70],

        // ── YouTube · Subscribers ──
        ['yt-subs-50',        'youtube',   'subscribers', 'channel', '50 Subscribers',  'YouTube Channel Subscribers', 500,   50,     null, null, 0, 10],
        ['yt-subs-100k',      'youtube',   'subscribers', 'channel', '100K Subscribers', 'YouTube Channel Subscribers', 9900, 100000, null, 0, 20],
        // ── YouTube · Likes ──
        ['yt-likes-100',      'youtube',   'likes',       'video',   '100 Likes',      'YouTube Video Likes',         500,   100,    null, null, 0, 30],
        ['yt-likes-50k',      'youtube',   'likes',       'video',   '50K Likes',      'YouTube Video Likes',         4900,  50000,  null, null, 0, 40],
        // ── YouTube · Views ──
        ['yt-views-10',       'youtube',   'views',       'video',   '10 Views',       'YouTube Video Views',         500,   10,     null, null, 0, 50],
        ['yt-views-10k',      'youtube',   'views',       'video',   '10,000 Views',   'YouTube Video Views',         9900,  10000,  null, null, 0, 60],
        // ── YouTube · Watch time (premium) ──
        ['yt-watch-4000h',    'youtube',   'watch_time',  'video',   '4,000 Hours Watch Time', 'YouTube Watch Time · Monetization Ready', 19900, 4000, 'premium', 1, 70],
    ],
];
