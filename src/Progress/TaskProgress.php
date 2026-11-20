<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Progress;

use OpenColibri\Starter\Core\Workspace;

/**
 * Progress of a long task step (images of a dataset, of a shop…), written to
 * .castor/import/var/.progress/<key>.json for the admin of the sales kit, which polls it while the task runs.
 */
final class TaskProgress
{
    /** @var array<string, array{label: string, done: int, total: int, current: ?string}> */
    private static array $steps = [];

    public static function datasetKey(string $slug): string
    {
        return 'dataset-' . $slug;
    }

    public static function shopKey(string $slug): string
    {
        return 'shop-' . $slug;
    }

    public static function path(string $key): string
    {
        return Workspace::importDir() . '/var/.progress/' . $key . '.json';
    }

    public static function start(string $key, string $label, int $total): void
    {
        self::$steps[$key] = ['label' => $label, 'done' => 0, 'total' => max(0, $total), 'current' => null];
        self::write($key);
    }

    public static function advance(string $key): void
    {
        if (!isset(self::$steps[$key])) {
            return;
        }

        ++self::$steps[$key]['done'];
        self::write($key);
    }

    public static function current(string $key, string $current): void
    {
        if (!isset(self::$steps[$key])) {
            return;
        }

        self::$steps[$key]['current'] = $current;
        self::write($key);
    }

    public static function finish(string $key): void
    {
        unset(self::$steps[$key]);

        if (is_file(self::path($key))) {
            @unlink(self::path($key));
        }
    }

    /**
     * @return array{label: string, done: int, total: int, current: ?string}|null
     */
    public static function step(string $key): ?array
    {
        return self::$steps[$key] ?? null;
    }

    private static function write(string $key): void
    {
        $path = self::path($key);
        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $path . '.' . getmypid() . '.tmp';
        $json = json_encode(self::$steps[$key] + ['updatedAt' => time()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false !== $json && false !== @file_put_contents($temporary, $json)) {
            @rename($temporary, $path);
        }
    }
}
