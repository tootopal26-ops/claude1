<?php

namespace App\Support;

use App\Models\StaffUser;
use Illuminate\Http\Request;

/**
 * Mini Max has two web portals that share one Laravel app, one `staff` guard
 * and one set of pages, but never share a session boundary: super-admin
 * accounts work in the Admin portal (/admin/*), every other account works in
 * the Staff portal (/staff/*). The EnsurePortalAccess middleware enforces that
 * on the server; this class only answers "which portal am I in?" so links,
 * redirects and login pages point at the right one.
 */
final class Portal
{
    public const ADMIN = 'admin';

    public const STAFF = 'staff';

    public static function for(?StaffUser $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return $user->is_super_admin ? self::ADMIN : self::STAFF;
    }

    /**
     * The signed-in account's portal; for guests (login and error pages), the
     * portal the request is addressed to.
     */
    public static function current(): string
    {
        return self::for(auth('staff')->user()) ?? self::fromRequest();
    }

    public static function fromRequest(?Request $request = null): string
    {
        $request ??= request();

        $name = (string) $request->route()?->getName();

        if (str_starts_with($name, self::STAFF.'.') || $request->is(self::STAFF, self::STAFF.'/*')) {
            return self::STAFF;
        }

        if (str_starts_with($name, self::ADMIN.'.') || $request->is(self::ADMIN, self::ADMIN.'/*')) {
            return self::ADMIN;
        }

        // Livewire's update endpoint has no portal of its own; it carries the
        // page the component lives on.
        $referrerPath = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);

        if (is_string($referrerPath) && preg_match('#^/'.self::STAFF.'(/|$)#', $referrerPath)) {
            return self::STAFF;
        }

        return self::ADMIN;
    }

    public static function isPortal(string $portal): bool
    {
        return in_array($portal, [self::ADMIN, self::STAFF], true);
    }

    /**
     * The full route name of a page ("tournaments.index") in a portal
     * (defaults to the current one).
     */
    public static function routeName(string $page, ?string $portal = null): string
    {
        return ($portal ?? self::current()).'.'.$page;
    }

    /**
     * @param  array<string, mixed>|mixed  $parameters
     */
    public static function route(string $page, mixed $parameters = [], bool $absolute = true, ?string $portal = null): string
    {
        return route(self::routeName($page, $portal), $parameters, $absolute);
    }

    public static function loginRouteName(string $portal): string
    {
        return self::routeName('login', $portal);
    }

    public static function homeUrl(?string $portal = null): string
    {
        return self::route('home', portal: $portal);
    }

    public static function loginUrl(?string $portal = null): string
    {
        return self::route('login', portal: $portal);
    }

    public static function label(string $portal): string
    {
        return $portal === self::STAFF ? 'Staff portal' : 'Admin portal';
    }

    /**
     * A same-site path inside the given portal that is safe to send a user to
     * after sign-in, or null. Rejects other hosts, other portals, the login and
     * logout endpoints, and anything that is not a plain GET page path.
     */
    public static function safeIntendedUrl(?string $url, string $portal, ?Request $request = null): ?string
    {
        if ($url === null || $url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x1f]/', $url)) {
            return null;
        }

        $request ??= request();
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port']) || isset($parts['user'])) {
            if (($parts['host'] ?? null) !== $request->getHost() || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                return null;
            }

            if (isset($parts['port']) && (int) $parts['port'] !== $request->getPort()) {
                return null;
            }
        }

        $path = $parts['path'] ?? '';

        if (! preg_match('#^/'.$portal.'/#', $path) || str_contains($path, '..') || str_starts_with($path, '//')) {
            return null;
        }

        if (preg_match('#^/'.$portal.'/(login|logout)(/|$)#', $path)) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
