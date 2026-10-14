<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validates and normalises what a customer types into "ENTER USERNAME OR LINK".
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
    private const IG_HOSTS = ['instagram.com', 'www.instagram.com', 'm.instagram.com', 'instagr.am', 'www.instagr.am'];
    private const YT_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtu.be'];

    private const IG_RESERVED = ['p', 'reel', 'reels', 'tv', 'explore', 'stories', 'accounts', 'direct', 'about', 'legal', 'developer',
        'directory', 'web', 'challenge', 'oauth', 'privacy', 'press', 'api', 'static', 'emails', 'nametag', 'topics', 'login', 'signup', 'download'];
    private const YT_RESERVED = ['watch', 'shorts', 'playlist', 'results', 'feed', 'live', 'embed', 'v', 'channel', 'c', 'user', 'account',
        'premium', 'gaming', 'music', 'kids', 'trending', 'hashtag', 'redirect', 'oembed', 'attribution_link', 'about', 't', 'howyoutubeworks', 'reporthistory'];

    /**
     * @return array{ok:bool,url?:string,display?:string,error?:string}
     */
    public static function validate(string $platform, string $kind, string $raw): array
    {
        $raw = self::clean($raw);
        if ($raw === '') {
            return self::fail(self::hint($platform, $kind));
        }
        if (mb_strlen($raw) > 300) {
            return self::fail('That is too long to be a username or link.');
        }
        return match ($platform) {
            'instagram' => self::instagram($kind, $raw),
            'youtube'   => self::youtube($kind, $raw),
            default     => self::fail('This platform is not supported.'),
        };
    }

    /** Placeholder text per platform/kind. */
    public static function placeholder(string $platform, string $kind): string
    {
        return match ([$platform, $kind]) {
            ['instagram', 'profile'] => '@username or https://instagram.com/username',
            ['instagram', 'post']    => 'https://instagram.com/p/… or /reel/…',
            ['youtube', 'channel']   => '@channel or https://youtube.com/@channel',
            ['youtube', 'video']     => 'https://youtube.com/watch?v=…',
            default                  => 'Username or link',
        };
    }

    public static function hint(string $platform, string $kind): string
    {
        return match ([$platform, $kind]) {
            ['instagram', 'profile'] => 'Enter your Instagram @username or profile link.',
            ['instagram', 'post']    => 'Paste the link of the Instagram post or reel.',
            ['youtube', 'channel']   => 'Enter your YouTube channel link or @handle.',
            ['youtube', 'video']     => 'Paste the link of the YouTube video.',
            default                  => 'Enter a username or link.',
        };
    }

    // ───────────────────────────── Instagram ────────────────────────────────

    private static function instagram(string $kind, string $raw): array
    {
        $u = self::asUrl($raw, self::IG_HOSTS);
        if ($u !== null && !empty($u['bad'])) {
            return self::fail($kind === 'post'
                ? 'That does not look like an Instagram post or reel link.'
                : 'That does not look like an Instagram link. Use instagram.com/yourusername or just your @username.');
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
            $name = ltrim($raw, '@');
            if (!self::validIgUsername($name)) {
                return self::fail('Enter a valid Instagram username (letters, numbers, dots and underscores) or profile link.');
            }
            return self::ok('https://www.instagram.com/' . $name . '/', '@' . $name);
        }
        $first = $u['segments'][0] ?? '';
        if ($first === '') {
            return self::fail('That link has no username. Use https://instagram.com/yourusername');
        }
        if (in_array(strtolower($first), self::IG_RESERVED, true)) {
            return self::fail('That looks like a post or page link. Paste your profile link or @username instead.');
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

    private static function youtube(string $kind, string $raw): array
    {
        $u = self::asUrl($raw, self::YT_HOSTS);
        if ($u !== null && !empty($u['bad'])) {
            return self::fail('That does not look like a YouTube link. Copy the link from YouTube and paste it here.');
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
            $handle = ltrim($raw, '@');
            if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/D', $handle)) {
                return self::fail('Enter a valid channel @handle or paste your channel link.');
            }
            return self::ok('https://www.youtube.com/@' . $handle, '@' . $handle);
        }
        $segs = $u['segments'];
        $first = $segs[0] ?? '';
        if (str_ends_with($u['host'], 'youtu.be') || in_array(strtolower($first), ['watch', 'shorts', 'live', 'embed', 'v'], true)) {
            return self::fail('That is a video link. This service needs your channel link or @handle.');
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
        return self::fail('That link does not look like a YouTube channel. Use youtube.com/@yourchannel');
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

    private static function clean(string $s): string
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
