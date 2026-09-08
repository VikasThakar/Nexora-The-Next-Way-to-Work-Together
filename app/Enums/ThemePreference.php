<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which appearance a person has asked for.
 *
 * Three cases rather than a boolean, because "follow the operating system" is a
 * genuine third answer and not a default for one of the other two: somebody
 * whose laptop turns dark at sunset wants Nexora to do the same, and storing
 * that as `dark` at dusk would be wrong by morning.
 *
 * The value is stored on the `users` row, so it follows a person between
 * devices — see the column added by
 * database/migrations/*_add_theme_preference_to_users_table.php. `Light` is
 * the default: it is the appearance the product had before this setting
 * existed, so anybody who never opens the control sees exactly the Nexora they
 * already know. Following the device is offered, never assumed.
 *
 * What this enum deliberately does not know is which of light or dark `System`
 * currently resolves to. Only the browser can answer that, it can change while
 * a page is open, and nothing on the server needs it: the server's job is to
 * render the preference so resources/js/theme.js can apply it without a flash.
 */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
            self::System => 'System',
        };
    }

    /**
     * What a screen reader should say about choosing this option.
     *
     * Spelled out rather than left to the visible label, which is one word and
     * sits in a group of three identically shaped buttons.
     */
    public function description(): string
    {
        return match ($this) {
            self::Light => 'Use the light appearance',
            self::Dark => 'Use the dark appearance',
            self::System => 'Match my device appearance',
        };
    }

    /**
     * The class the <html> element carries for this preference, or null when
     * only the browser can decide.
     *
     * Returning null for System is the point: the server must not guess. The
     * inline script in the layout head resolves it against
     * `prefers-color-scheme` before the first paint.
     */
    public function htmlClass(): ?string
    {
        return match ($this) {
            self::Light => '',
            self::Dark => 'dark',
            self::System => null,
        };
    }

    /**
     * The appearance somebody gets before they have chosen one.
     *
     * Light, because that is what the product looked like before this setting
     * existed: a new account, or a browser with nothing stored, opens the
     * Nexora it already knows rather than whatever the laptop happens to
     * prefer that evening. Named here as well as defaulted in the database and
     * in resources/js/theme.js so the three cannot drift apart.
     */
    public static function default(): self
    {
        return self::Light;
    }

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::Light, self::Dark, self::System];
    }
}
