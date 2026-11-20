<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Util;

use function Castor\io;

/**
 * Task output that must not fail outside of a Castor run (unit tests, a function called from another tool).
 */
final class Log
{
    public static function comment(string $message): void
    {
        try {
            io()->comment($message);
        } catch (\LogicException) {
            // Castor is not bootstrapped.
        }
    }

    public static function warning(string $message): void
    {
        try {
            io()->warning($message);
        } catch (\LogicException) {
            // Castor is not bootstrapped.
        }
    }
}
