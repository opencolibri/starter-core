<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Dataset;

/**
 * The datasets of every registered directory (see DatasetDirectories). A built-in dataset wins over an editable one
 * of the same slug.
 */
final class Datasets
{
    /**
     * @return array<string, Dataset> by slug, sorted
     */
    public static function all(): array
    {
        $datasets = [];

        foreach (DatasetDirectories::entries() as $entry) {
            foreach (glob($entry['directory'] . '/*', \GLOB_ONLYDIR) ?: [] as $path) {
                if (Dataset::isDataset($path)) {
                    $datasets[basename($path)] ??= new Dataset(basename($path), $path, $entry['builtIn']);
                }
            }
        }

        ksort($datasets);

        return $datasets;
    }

    public static function find(string $slug): ?Dataset
    {
        return self::all()[trim($slug)] ?? null;
    }

    public static function get(string $slug): Dataset
    {
        return self::find($slug) ?? throw new \RuntimeException(\sprintf(
            'Unknown dataset "%s". Run "castor sylius:dataset:list" to list the datasets.',
            $slug,
        ));
    }
}
