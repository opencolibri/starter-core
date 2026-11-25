<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Util;

use OpenColibri\Starter\Core\App;

use function Castor\fs;

final readonly class Javascript
{
    public static function addImport(App $app, string $file, string $resource): void
    {
        $realFile = $app->directory() . '/' . $file;

        $content = fs()->readFile($realFile);

        $import = \sprintf("import '%s';", $resource);

        if (str_contains($content, $import)) {
            return;
        }

        if (preg_match('/^import\b/m', $content)) {
            $replaced = preg_replace(
                '/((?:^import\b.*?;\R?)+)/ms',
                "$1{$import}\n",
                $content,
                1,
            );

            if (null !== $replaced) {
                $content = $replaced;
            }
        } else {
            $content = $import . "\n" . ltrim($content);
        }

        fs()->dumpFile($realFile, $content);
    }

    public static function removeImport(App $app, string $file, string $resource): void
    {
        $realFile = $app->directory() . '/' . $file;

        $content = fs()->readFile($realFile);

        $import = \sprintf("import '%s';", $resource);

        if (!str_contains($content, $import)) {
            return;
        }

        $replaced = preg_replace(
            '/^' . preg_quote($import, '/') . '\R?/m',
            '',
            $content,
            1,
        );

        if (null !== $replaced) {
            $content = $replaced;
        }

        fs()->dumpFile($realFile, $content);
    }

    /**
     * Removes the imports of a Composer package assets ("import '@vendor/<package>/…';") from the shop and admin
     * entrypoints, the way Sylius-Standard wires the payment plugins it ships.
     */
    public static function removeVendorImports(App $app, string $package): void
    {
        foreach (['assets/shop/entrypoint.js', 'assets/admin/entrypoint.js'] as $file) {
            $realFile = $app->directory() . '/' . $file;

            if (!is_file($realFile)) {
                continue;
            }

            $content = fs()->readFile($realFile);
            $replaced = preg_replace('/^import\s+[\'"]@vendor\/' . preg_quote($package, '/') . '\/[^\'"]*[\'"];?[^\S\r\n]*\R?/m', '', $content);

            if (null !== $replaced && $replaced !== $content) {
                fs()->dumpFile($realFile, $replaced);
            }
        }
    }
}
