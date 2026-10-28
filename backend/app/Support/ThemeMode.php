<?php

namespace App\Support;

use App\Models\StaffUser;

/**
 * Day / Night mode for the Admin and Staff panels. The preference is saved
 * per account and per portal (staff_users.theme_modes) and rendered onto
 * <html data-mode-pref> by the layouts, so a signed-in page starts in the
 * right mode on first paint; "system" is resolved by the inline boot script
 * (components/theme-boot.blade.php) before paint. Sign-in and error pages for
 * guests fall back to the last mode used in that portal on the device.
 */
final class ThemeMode
{
    public const LIGHT = 'light';

    public const DARK = 'dark';

    public const SYSTEM = 'system';

    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return array_keys(config('minimax.theme_modes'));
    }

    public static function isValid(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, self::options(), true);
    }

    /**
     * The saved preference for an account in a portal, the default for a
     * signed-in account that never chose, or null for a guest.
     */
    public static function preference(?StaffUser $user, string $portal): ?string
    {
        if ($user === null) {
            return null;
        }

        $saved = $user->theme_modes[$portal] ?? null;

        return self::isValid($saved) ? $saved : config('minimax.theme_mode_default');
    }

    /**
     * The mode the server paints: "system" and unknown preferences start dark
     * and the boot script corrects them before first paint.
     */
    public static function initial(?string $preference): string
    {
        return $preference === self::LIGHT ? self::LIGHT : self::DARK;
    }

    public static function save(StaffUser $user, string $portal, string $mode): void
    {
        $user->theme_modes = [...($user->theme_modes ?? []), $portal => $mode];
        $user->save();
    }
}
