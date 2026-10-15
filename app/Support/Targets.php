<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validates and normalises what a customer types into the order form.
 *
 * Two input styles:
 *   - Instagram Followers / YouTube Subscribers ("link only", see requiresLink()): the customer pastes the ACCOUNT LINK —
 *     "Instagram Profile Link" / "YouTube Channel Link". A bare @username / @handle is not accepted;
 *   - every other service keeps its "username or link" (profile) or post / video link input.
 *
 * Rules of thumb:
 *   - be GENEROUS about formatting (scheme/www/mobile/trailing slash/tracking
 *     params/@ prefix are all fine) because URL shapes vary;
 *   - be STRICT about meaning (right platform host, right kind of target) and
 *     about safety (http/https only, exact host match, no credentials/ports).
 *
 * The browser has an identical implementation (resources/js/targets.js); both are
 * verified against tests/fixtures/targets.json. The SERVER result is the only one
 * that counts: it is re-run on checkout and its normalised URL is what gets
 * stored and sent to the provider.
 */
final class Targets
{
    /** Service types that are sold per ACCOUNT: the customer pastes the account's link. */
    private const LINK_ONLY_TYPES = ['followers', 'subscribers'];

    private const IG_HOSTS = ['instagram.com', 'www.instagram.com', 'm.instagram.com', 'instagr.am', 'www.instagr.am'];
    private const YT_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtu.be'];

    private const IG_RESERVED = ['p', 'reel', 'reels', 'tv', 'explore', 'stories', 'accounts', 'direct', 'about', 'legal', 'developer',
        'directory', 'web', 'challenge', 'oauth', 'privacy', 'press', 'api', 'static', 'emails', 'nametag', 'topics', 'login', 'signup', 'download'];
    private const YT_RESERVED = ['watch', 'shorts', 'playlist', 'results', 'feed', 'live', 'embed', 'v', 'channel', 'c', 'user', 'account',
        'premium', 'gaming', 'music', 'kids', 'trending', 'hashtag', 'redirect', 'oembed', 'attribution_link', 'about', 't', 'howyoutubeworks', 'reporthistory'];

    /**
     * Instagram Followers and YouTube Subscribers take the account LINK (label "Instagram Profile Link" / "YouTube Channel Link").
     * Every other service keeps its current input (Blue Tick: username or link; likes / views / watch time: the post or video link).
     */
    public static function requiresLink(string $platform, string $kind, string $serviceType): bool
    {
        return in_array($serviceType, self::LINK_ONLY_TYPES, true)
            && (($platform === 'instagram' && $kind === 'profile') || ($platform === 'youtube' && $kind === 'channel'));
    }

    /** The same rule for a stored order, which keeps the platform and the service type (not the service's target kind). */
    public static function orderHasAccountLink(string $platform, string $serviceType): bool
    {
        return in_array($serviceType, self::LINK_ONLY_TYPES, true) && in_array($platform, ['instagram', 'youtube'], true);
    }

    /**
     * @param bool $linkOnly the service wants the account link, so a bare username / handle is refused (see requiresLink())
     * @return array{ok:bool,url?:string,display?:string,error?:string}
     */
    public static function validate(string $platform, string $kind, string $raw, bool $linkOnly = false): array
    {
        $raw = self::clean($raw);
        if ($raw === '') {
            return self::fail(self::hint($platform, $kind, $linkOnly));
        }
        if (mb_strlen($raw) > 300) {
            return self::fail($linkOnly ? 'That is too long to be a profile or channel link.' : 'That is too long to be a username or link.');
        }
        return match ($platform) {
            'instagram' => self::instagram($kind, $raw, $linkOnly),
            'youtube'   => self::youtube($kind, $raw, $linkOnly),
            default     => self::fail('This platform is not supported.'),
        };
    }

    /** The field's label on the storefront card. */
    public static function label(string $platform, bool $linkOnly = false): string
    {
        if (!$linkOnly) {
            return 'Enter username or link';
        }
        return match ($platform) {
            'instagram' => 'Instagram Profile Link',
            'youtube'   => 'YouTube Channel Link',
            default     => 'Account link',
        };
    }

    /** Placeholder text per platform/kind. */
    public static function placeholder(string $platform, string $kind, bool $linkOnly = false): string
    {
        if ($linkOnly) {
            return match ($platform) {
                'instagram' => 'https://www.instagram.com/username/',
                'youtube'   => 'https://www.youtube.com/@channelname',
                default     => 'https://',
            };
        }
        return match ([$platform, $kind]) {
            ['instagram', 'profile'] => '@username or https://instagram.com/username',
            ['instagram', 'post']    => 'https://instagram.com/p/… or /reel/…',
            ['youtube', 'channel']   => '@channel or https://youtube.com/@channel',
            ['youtube', 'video']     => 'https://youtube.com/watch?v=…',
            default                  => 'Username or link',
        };
    }

    public static function hint(string $platform, string $kind, bool $linkOnly = false): string
    {
        if ($linkOnly) {
            return match ($platform) {
                'instagram' => 'Paste the link to your Instagram profile. In the app: Profile → Share profile → Copy link.',
                'youtube'   => 'Paste the link to your YouTube channel — youtube.com/@name or youtube.com/channel/UC…',
                default     => 'Paste the link to the account.',
            };
        }
        return match ([$platform, $kind]) {
            ['instagram', 'profile'] => 'Enter your Instagram @username or profile link.',
            ['instagram', 'post']    => 'Paste the link of the Instagram post or reel.',
            ['youtube', 'channel']   => 'Enter your YouTube channel link or @handle.',
            ['youtube', 'video']     => 'Paste the link of the YouTube video.',
            default                  => 'Enter a username or link.',
        };
    }

    // ───────────────────────────── Instagram ────────────────────────────────

    private static function instagram(string $kind, string $raw, bool $linkOnly): array
    {
        $u = self::asUrl($raw, self::IG_HOSTS);
        if ($u !== null && !empty($u['bad'])) {
            return self::fail($kind === 'post'
                ? 'That does not look like an Instagram post or reel link.'
                : ($linkOnly
                    ? 'That is not an Instagram profile link. Use a link like https://www.instagram.com/username/'
                    : 'That does not look like an Instagram link. Use instagram.com/yourusername or just your @username.'));
        }

        if ($kind === 'post') {
            if ($u === null) {
                return self::fail(self::looksLikeUsername($raw, 30)
                    ? 'This service needs the link of a post or reel, not a username.'
                    : 'Paste the full link of the Instagram post or reel.');
            }
            $segs = $u['segments'];
            foreach ([0, 1] as $i) {   // /p/CODE  or  /username/p/CODE
                $type = strtolower($segs[$i] ?? '');
                $code = $segs[$i + 1] ?? '';
                if (in_array($type, ['p', 'reel', 'reels', 'tv'], true) && preg_match('/^[A-Za-z0-9_-]{5,40}$/D', $code)) {
                    $path = $type === 'reels' ? 'reel' : $type;
                    return self::ok('https://www.instagram.com/' . $path . '/' . $code . '/', $path . '/' . $code);
                }
            }
            return self::fail('That link does not point to a post or reel. Open the post, tap “Copy link” and paste it here.');
        }

        // profile
        if ($u === null) {
            if ($linkOnly) {   // the account LINK is required, a bare username is not enough
                return self::fail(self::looksLikeUsername($raw, 30)
                    ? 'Please paste your full Instagram profile link, not just the username. Example: https://www.instagram.com/username/'
                    : 'Paste your Instagram profile link. Example: https://www.instagram.com/username/');
            }
            $name = ltrim($raw, '@');
            if (!self::validIgUsername($name)) {
                return self::fail('Enter a valid Instagram username (letters, numbers, dots and underscores) or profile link.');
            }
            return self::ok('https://www.instagram.com/' . $name . '/', '@' . $name);
        }
        $first = $u['segments'][0] ?? '';
        if (strtolower($first) === '_u' && isset($u['segments'][1])) {
            $first = $u['segments'][1];   // the app's share link: instagram.com/_u/username
        }
        if ($first === '') {
            return self::fail($linkOnly ? 'That link has no username in it. Use https://www.instagram.com/username/' : 'That link has no username. Use https://instagram.com/yourusername');
        }
        if (in_array(strtolower($first), self::IG_RESERVED, true)) {
            return self::fail($linkOnly
                ? 'That looks like a post or page link, not a profile. Paste your profile link: https://www.instagram.com/username/'
                : 'That looks like a post or page link. Paste your profile link or @username instead.');
        }
        $name = ltrim($first, '@');
        if (!self::validIgUsername($name)) {
            return self::fail('That profile link does not contain a valid Instagram username.');
        }
        return self::ok('https://www.instagram.com/' . $name . '/', '@' . $name);
    }

    private static function validIgUsername(string $n): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._]{1,30}$/D', $n) && (bool) preg_match('/[A-Za-z0-9_]/', $n);
    }

    // ───────────────────────────── YouTube ──────────────────────────────────

    private static function youtube(string $kind, string $raw, bool $linkOnly): array
    {
        $u = self::asUrl($raw, self::YT_HOSTS);
        if ($u !== null && !empty($u['bad'])) {
            return self::fail($linkOnly && $kind === 'channel'
                ? 'That is not a YouTube channel link. Copy the link from YouTube, e.g. https://www.youtube.com/@channelname'
                : 'That does not look like a YouTube link. Copy the link from YouTube and paste it here.');
        }

        if ($kind === 'video') {
            if ($u === null) {
                return self::fail('Paste the full link of the YouTube video.');
            }
            $id = null;
            $shorts = false;
            $segs = $u['segments'];
            if (str_ends_with($u['host'], 'youtu.be')) {
                $id = $segs[0] ?? null;
            } else {
                $first = strtolower($segs[0] ?? '');
                if ($first === 'watch') {
                    $id = $u['query']['v'] ?? null;
                } elseif (in_array($first, ['shorts', 'live', 'embed', 'v'], true)) {
                    $id = $segs[1] ?? null;
                    $shorts = $first === 'shorts';
                }
            }
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
                return $shorts
                    ? self::ok('https://www.youtube.com/shorts/' . $id, 'shorts/' . $id)
                    : self::ok('https://www.youtube.com/watch?v=' . $id, 'video ' . $id);
            }
            if (self::isChannelPath($u)) {
                return self::fail('That is a channel link. This service needs the link of a specific video.');
            }
            return self::fail('That link does not point to a YouTube video. Open the video, tap Share → Copy link.');
        }

        // channel
        if ($u === null) {
            if ($linkOnly) {   // the channel LINK is required, a bare @handle is not enough
                return self::fail(preg_match('/^@?[A-Za-z0-9._-]{1,30}$/D', $raw)
                    ? 'Please paste your full YouTube channel link, not just the @handle. Example: https://www.youtube.com/@channelname'
                    : 'Paste your YouTube channel link. Example: https://www.youtube.com/@channelname');
            }
            $handle = ltrim($raw, '@');
            if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/D', $handle)) {
                return self::fail('Enter a valid channel @handle or paste your channel link.');
            }
            return self::ok('https://www.youtube.com/@' . $handle, '@' . $handle);
        }
        $segs = $u['segments'];
        $first = $segs[0] ?? '';
        if (str_ends_with($u['host'], 'youtu.be') || in_array(strtolower($first), ['watch', 'shorts', 'live', 'embed', 'v'], true)) {
            return self::fail($linkOnly
                ? 'That is a video link. Paste your channel link instead, e.g. https://www.youtube.com/@channelname'
                : 'That is a video link. This service needs your channel link or @handle.');
        }
        if (isset($first[0]) && $first[0] === '@') {
            $h = substr($first, 1);
            if (preg_match('/^[A-Za-z0-9._-]{3,30}$/D', $h)) {
                return self::ok('https://www.youtube.com/@' . $h, '@' . $h);
            }
        } elseif (strtolower($first) === 'channel' && preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $segs[1] ?? '')) {
            return self::ok('https://www.youtube.com/channel/' . $segs[1], 'channel/' . $segs[1]);
        } elseif (in_array(strtolower($first), ['c', 'user'], true) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $segs[1] ?? '')) {
            return self::ok('https://www.youtube.com/' . strtolower($first) . '/' . $segs[1], strtolower($first) . '/' . $segs[1]);
        } elseif ($first !== '' && !in_array(strtolower($first), self::YT_RESERVED, true) && preg_match('/^[A-Za-z0-9._-]{3,100}$/D', $first)) {
            return self::ok('https://www.youtube.com/' . $first, $first);   // legacy custom URL
        }
        return self::fail($linkOnly
            ? 'That link does not look like a YouTube channel. Use https://www.youtube.com/@channelname or https://www.youtube.com/channel/UC…'
            : 'That link does not look like a YouTube channel. Use youtube.com/@yourchannel');
    }

    private static function isChannelPath(array $u): bool
    {
        $first = $u['segments'][0] ?? '';
        return ($first !== '' && $first[0] === '@') || in_array(strtolower($first), ['channel', 'c', 'user'], true);
    }

    // ───────────────────────────── helpers ──────────────────────────────────

    /**
     * Parse $raw as a URL on one of $hosts. Returns null when it is not a URL
     * at all (so callers can treat it as a bare username/handle).
     *
     * @param list<string> $hosts
     * @return array{host:string,segments:list<string>,query:array<string,string>}|null
     */
    private static function asUrl(string $raw, array $hosts): ?array
    {
        $candidate = $raw;
        $hasScheme = (bool) preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate);
        if (!$hasScheme) {
            if ($raw[0] === '@' || !preg_match('#^[A-Za-z0-9.\-]+\.[A-Za-z]{2,}(/|$|\?|:)#', $candidate)) {
                return null; // not host-looking → bare username / handle
            }
            $candidate = 'https://' . $candidate;
        }
        $p = parse_url($candidate);
        if (!is_array($p) || empty($p['host'])) {
            return self::badUrl();
        }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = rtrim(strtolower($p['host']), '.');
        if (!in_array($scheme, ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true))
            || !in_array($host, $hosts, true)) {
            return self::badUrl();
        }
        $path = (string) ($p['path'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        $segments = array_map('rawurldecode', $segments);
        parse_str((string) ($p['query'] ?? ''), $q);
        $query = [];
        foreach ($q as $k => $v) {
            if (is_string($v)) {
                $query[(string) $k] = $v;
            }
        }
        return ['host' => $host, 'segments' => $segments, 'query' => $query];
    }

    /** Sentinel for "looks like a URL but is not an acceptable one". */
    private static function badUrl(): array
    {
        return ['bad' => true, 'host' => '', 'segments' => [], 'query' => []];
    }

    private static function looksLikeUsername(string $s, int $max): bool
    {
        return (bool) preg_match('/^@?[A-Za-z0-9._]{1,' . $max . '}$/D', $s);
    }

    /** Strip control / zero-width characters and surrounding whitespace (what the customer meant, not what the clipboard added). */
    public static function clean(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
        return trim($s);
    }

    private static function ok(string $url, string $display): array
    {
        return ['ok' => true, 'url' => $url, 'display' => $display];
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'error' => $msg];
    }
}
