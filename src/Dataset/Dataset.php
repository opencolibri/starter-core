<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Dataset;

use Symfony\Component\Yaml\Yaml;

/**
 * A catalog ready to be loaded into Sylius, in a directory:
 *
 *     dataset.yaml       name, description, url, source, theme, created_at (all optional)
 *     products.yaml      products: [{title, description, collection_name | collection, price_eur, image, code}]
 *     collections.yaml   collections: [{name, code, parent, image}]
 *     media/             the images the YAML points at ("media/<file>.webp")
 *
 * A product belongs to the collection named by collection_name, or of code collection (a grouped menu may repeat a
 * collection name across groups: its collections have a code). A collection with a parent (the code or name of
 * another collection) is shown under it in the shop menu. code (optional) keeps the product code stable. An image is a path relative to the dataset directory; a remote URL
 * or an image_prompt is only a leftover of a dataset whose images were not stored yet (sylius:dataset:images).
 *
 * The format is final: loading a dataset needs neither the source site nor an AI.
 */
final readonly class Dataset
{
    public const string CONFIG_FILE = 'dataset.yaml';
    public const string PRODUCTS_FILE = 'products.yaml';
    public const string COLLECTIONS_FILE = 'collections.yaml';
    public const string MEDIA_DIR = 'media';

    /** Files copied with media/ when a dataset is exported or copied. */
    public const array FILES = [self::CONFIG_FILE, self::PRODUCTS_FILE, self::COLLECTIONS_FILE];

    public function __construct(
        public string $slug,
        public string $path,
        public bool $builtIn = false,
    ) {}

    public static function isDataset(string $path): bool
    {
        return is_file($path . '/' . self::PRODUCTS_FILE);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return self::readYaml($this->path . '/' . self::CONFIG_FILE) ?? [];
    }

    public function name(): string
    {
        $name = trim((string) ($this->config()['name'] ?? ''));

        if ('' === $name) {
            $name = trim((string) ((self::readYaml($this->path . '/' . self::PRODUCTS_FILE) ?? [])['name'] ?? ''));
        }

        return '' !== $name ? $name : ucwords(str_replace(['-', '_'], ' ', $this->slug));
    }

    public function configValue(string $key): ?string
    {
        $value = $this->config()[$key] ?? null;

        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        return self::items($this->path . '/' . self::PRODUCTS_FILE, 'products');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collections(): array
    {
        return self::items($this->path . '/' . self::COLLECTIONS_FILE, 'collections');
    }

    public function mediaDir(): string
    {
        return $this->path . '/' . self::MEDIA_DIR;
    }

    /**
     * The file of a stored image ("media/<file>"), or null for a remote, missing or unsafe one.
     */
    public function imageFile(?string $image): ?string
    {
        $image = str_replace('\\', '/', trim((string) $image));

        if (!self::isLocalImage($image) || str_starts_with($image, '/') || \in_array('..', explode('/', $image), true)) {
            return null;
        }

        $file = $this->path . '/' . preg_replace('#^(\./)+#', '', $image);

        return is_file($file) && filesize($file) > 0 ? $file : null;
    }

    public static function isLocalImage(?string $image): bool
    {
        $image = trim((string) $image);

        return '' !== $image && !str_starts_with($image, '//') && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $image);
    }

    /**
     * @return array{
     *     slug: string,
     *     name: string,
     *     description: string|null,
     *     url: string|null,
     *     source: string|null,
     *     theme: string|null,
     *     builtIn: bool,
     *     productCount: int,
     *     collectionCount: int,
     *     imageCount: int,
     *     createdAt: string|null,
     * }
     */
    public function describe(): array
    {
        $products = $this->products();
        $imageCount = \count(array_filter(
            $products,
            fn(array $product): bool => null !== $this->imageFile(\is_string($product['image'] ?? null) ? $product['image'] : null),
        ));

        return [
            'slug' => $this->slug,
            'name' => $this->name(),
            'description' => $this->configValue('description'),
            'url' => $this->configValue('url'),
            'source' => $this->configValue('source'),
            'theme' => $this->configValue('theme'),
            'builtIn' => $this->builtIn,
            'productCount' => \count($products),
            'collectionCount' => \count($this->collections()),
            'imageCount' => $imageCount,
            'createdAt' => $this->configValue('created_at'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(string $file, string $key): array
    {
        try {
            $items = (self::readYaml($file) ?? [])[$key] ?? [];
        } catch (\Throwable) {
            return [];
        }

        return \is_array($items) ? array_values(array_filter($items, \is_array(...))) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readYaml(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = Yaml::parseFile($file);

        return \is_array($data) ? $data : null;
    }
}
