<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deliberately small user-agent / referrer classification for privacy-conscious
 * analytics: we keep only a device class, a browser family and an OS family —
 * never the raw user-agent string, never the IP address.
 */
final class Ua
{
    public static function isBot(string $ua): bool
    {
        if ($ua === '') {
            return true;
        }
        return (bool) preg_match('/(bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|lighthouse|pingdom|uptime|monitor|curl\/|wget|python-requests|python-urllib|go-http-client|java\/|libwww|httpclient|okhttp\/|scrapy|phantomjs|postman|insomnia)/i', $ua);
    }

    public static function device(string $ua): string
    {
        if (preg_match('/(ipad|tablet|playbook|silk)|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/(mobi|iphone|ipod|android|blackberry|opera mini|iemobile)/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/edg(e|a|ios)?\//i', $ua)          => 'Edge',
            (bool) preg_match('/(opr|opera)\//i', $ua)            => 'Opera',
            (bool) preg_match('/samsungbrowser\//i', $ua)         => 'Samsung',
            (bool) preg_match('/(firefox|fxios)\//i', $ua)        => 'Firefox',
            (bool) preg_match('/(chrome|crios)\//i', $ua)         => 'Chrome',
            (bool) preg_match('/safari\//i', $ua)                 => 'Safari',
            default                                               => 'Other',
        };
    }

    public static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/android/i', $ua)                  => 'Android',
            (bool) preg_match('/(iphone|ipad|ipod)/i', $ua)       => 'iOS',
            (bool) preg_match('/windows/i', $ua)                  => 'Windows',
            (bool) preg_match('/(macintosh|mac os x)/i', $ua)     => 'macOS',
            (bool) preg_match('/linux/i', $ua)                    => 'Linux',
            default                                               => 'Other',
        };
    }

    /**
     * Traffic source bucket: instagram | youtube | direct | other.
     * UTM source wins over the referrer.
     */
    public static function source(?string $utmSource, ?string $refererHost, ?string $ownHost): string
    {
        $utm = strtolower(trim((string) $utmSource));
        if ($utm !== '') {
            if (preg_match('/(^|[^a-z])(ig|insta|instagram)([^a-z]|$)/', $utm)) {
                return 'instagram';
            }
            if (preg_match('/(^|[^a-z])(yt|youtube)([^a-z]|$)/', $utm)) {
                return 'youtube';
            }
        }
        $h = strtolower(trim((string) $refererHost));
        if ($h === '' || ($ownHost !== null && $h === strtolower($ownHost))) {
            return $utm !== '' ? 'other' : 'direct';
        }
        if (preg_match('/(^|\.)(instagram\.com|instagr\.am)$/', $h)) {
            return 'instagram';
        }
        if (preg_match('/(^|\.)(youtube\.com|youtu\.be)$/', $h)) {
            return 'youtube';
        }
        return 'other';
    }

    /** Host part of a referrer URL (no path/query — privacy). */
    public static function host(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) && $h !== '' ? mb_substr(strtolower($h), 0, 190) : null;
    }
}
