<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Theme;

/**
 * The name sylius/theme-bundle gives a theme of opencolibri/starter-themes installed with the theme-bundle target,
 * stored in sylius_channel.theme_name. Shared by the packages that pick the theme of a channel.
 */
final class ThemeName
{
    /** The Sylius look: no theme on the channel. */
    public const DEFAULT = 'default';

    /**
     * @return string|null null for the Sylius look
     */
    public static function of(string $theme): ?string
    {
        return self::DEFAULT === $theme ? null : 'opencolibri/' . str_replace('_', '-', $theme);
    }
}
