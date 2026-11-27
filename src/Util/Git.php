<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Util;

use OpenColibri\Starter\Core\App;
use Symfony\Component\Process\Process;

final readonly class Git
{
    /**
     * A file of the application as of its last commit.
     *
     * @param string $path relative to the application directory
     *
     * @return string|false|null its content, false when it is not committed, null when the application is not in a git
     *                           repository or the enclosing one ignores it (nothing tells what was committed)
     */
    public static function committedFile(App $app, string $path): string|false|null
    {
        $directory = $app->directory();

        if (!self::git($directory, ['rev-parse', '--is-inside-work-tree'])->isSuccessful()
            || self::git($directory, ['check-ignore', '--quiet', '.'])->isSuccessful()) {
            return null;
        }

        $show = self::git($directory, ['show', 'HEAD:./' . ltrim($path, '/')]);

        return $show->isSuccessful() ? $show->getOutput() : false;
    }

    /**
     * @param list<string> $arguments
     */
    private static function git(string $directory, array $arguments): Process
    {
        $process = new Process(['git', '-C', $directory, ...$arguments]);
        $process->run();

        return $process;
    }
}
