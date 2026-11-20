<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Dataset;

/**
 * Packages providing datasets register their directory here, usually from a file autoloaded by Composer: the
 * ready-made datasets of opencolibri/starter-datasets (built-in: read only), the datasets built by
 * opencolibri/starter-dataset-builder (editable)… The packages loading datasets list them through Datasets, without
 * knowing the packages that provide them.
 *
 * Each sub-directory of a registered directory holding a products.yaml is a dataset (see Dataset).
 */
final class DatasetDirectories
{
    /** @var array<string, array{directory: string|\Closure(): string, builtIn: bool}> */
    private static array $directories = [];

    /**
     * @param string|\Closure(): string $directory a closure when the directory depends on the project (resolved when
     *                                             the datasets are listed, not when the package is autoloaded)
     * @param bool                      $builtIn   built-in datasets are read only, and win over the others on a slug
     */
    public static function register(string $name, string|\Closure $directory, bool $builtIn = true): void
    {
        self::$directories[$name] = ['directory' => $directory, 'builtIn' => $builtIn];
    }

    public static function unregister(string $name): void
    {
        unset(self::$directories[$name]);
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_map(static fn(array $entry): string => $entry['directory'], self::entries());
    }

    /**
     * Built-in directories first.
     *
     * @return array<string, array{directory: string, builtIn: bool}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (self::$directories as $name => $entry) {
            $directory = $entry['directory'] instanceof \Closure ? ($entry['directory'])() : $entry['directory'];
            $entries[$name] = ['directory' => rtrim($directory, '/\\'), 'builtIn' => $entry['builtIn']];
        }

        uasort($entries, static fn(array $a, array $b): int => $b['builtIn'] <=> $a['builtIn']);

        return $entries;
    }
}
