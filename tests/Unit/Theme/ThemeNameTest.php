<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Tests\Unit\Theme;

use OpenColibri\Starter\Core\Theme\ThemeName;
use PHPUnit\Framework\TestCase;

final class ThemeNameTest extends TestCase
{
    public function testItNamesAThemeLikeSyliusThemeBundle(): void
    {
        static::assertSame('opencolibri/cellar', ThemeName::of('cellar'));
        static::assertSame('opencolibri/retro-gaming', ThemeName::of('retro_gaming'));
    }

    public function testTheSyliusLookHasNoThemeName(): void
    {
        static::assertNull(ThemeName::of(ThemeName::DEFAULT));
    }
}
