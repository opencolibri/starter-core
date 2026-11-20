<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core;

use function Castor\variable;

/**
 * The Castor project the tasks run from: its root directory and the ".castor/" directory where the dataset, fixtures
 * and sales kit packages keep their files (datasets built from the tasks, shops, task progress…).
 */
final class Workspace
{
    private static ?string $root = null;

    /**
     * Pins the root directory (tests, or a task running outside of the project).
     */
    public static function setRoot(?string $root): void
    {
        self::$root = null !== $root ? rtrim($root, '/\\') : null;
    }

    public static function root(): string
    {
        if (null !== self::$root) {
            return self::$root;
        }

        try {
            $candidate = rtrim((string) variable('root_dir'), '/\\');

            if ('' !== $candidate && is_dir($candidate . '/.castor')) {
                return self::$root = $candidate;
            }
        } catch (\Throwable) {
            // Castor is not bootstrapped, or the variable is not defined.
        }

        $cwd = getcwd();

        if (false === $cwd) {
            throw new \RuntimeException('Cannot resolve the project root directory.');
        }

        return self::$root = rtrim($cwd, '/\\');
    }

    public static function castorDir(): string
    {
        return self::root() . '/.castor';
    }

    /**
     * Holds the datasets built from the tasks (datasets/), the shops of the sales kit (var/) and the task progress
     * (var/.progress/). The admin of the sales kit reads these paths: keep them stable.
     */
    public static function importDir(): string
    {
        return self::castorDir() . '/import';
    }
}
